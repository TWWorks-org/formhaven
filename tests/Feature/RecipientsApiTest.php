<?php

namespace Tests\Feature;

use App\Mail\RecipientConfirmation;
use App\Mcp\Servers\FormHavenServer;
use App\Mcp\Tools\AddRecipientTool;
use App\Mcp\Tools\ListFormsTool;
use App\Mcp\Tools\RemoveRecipientTool;
use App\Mcp\Tools\UpdateFormEmailsTool;
use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RecipientsApiTest extends TestCase
{
    use RefreshDatabase;

    private function key(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_an_agent_adds_a_client_over_rest_and_the_client_only_gets_mail_after_confirming(): void
    {
        $form = Form::factory()->create();
        $headers = $this->key($form->user);
        $base = "/api/v1/forms/{$form->public_id}";

        $this->postJson("{$base}/recipients", ['email' => ' Client@Bakery.example '], $headers)
            ->assertCreated()
            ->assertJsonPath('data.email', 'client@bakery.example')
            ->assertJsonPath('data.confirmed', false);

        $this->getJson($base, $headers)->assertOk()->assertJsonPath('data.recipients.0.confirmed', false);

        $this->post("/f/{$form->public_id}", ['message' => 'Before'])->assertRedirect();
        $to = fn () => collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress());
        $this->assertSame(['client@bakery.example', $form->user->email], $to()->all(), 'only the confirmation and the owner copy');

        $this->post($form->recipients()->sole()->confirmUrl())->assertOk();
        $this->getJson($base, $headers)->assertJsonPath('data.recipients.0.confirmed', true);

        $this->post("/f/{$form->public_id}", ['message' => 'After'])->assertRedirect();
        $this->assertContains('client@bakery.example', $to()->slice(2)->all());
    }

    public function test_rest_refuses_what_the_dashboard_refuses(): void
    {
        Mail::fake();
        $form = Form::factory()->create();
        $headers = $this->key($form->user);
        $base = "/api/v1/forms/{$form->public_id}";

        $this->postJson("{$base}/recipients", ['email' => $form->user->email], $headers)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson("{$base}/recipients", ['email' => 'not an email'], $headers)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson("{$base}/recipients", [], $headers)->assertUnprocessable()->assertJsonValidationErrors('email');

        foreach (range(1, 5) as $n) {
            $this->postJson("{$base}/recipients", ['email' => "c{$n}@client.example"], $headers)->assertCreated();
        }
        $this->postJson("{$base}/recipients", ['email' => 'c6@client.example'], $headers)->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'A form can email up to 5 addresses. Remove one first.');
        $this->postJson("{$base}/recipients", ['email' => 'c1@client.example'], $headers)->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'That address is already on this form.');

        Mail::assertQueued(RecipientConfirmation::class, 5);
    }

    public function test_resend_and_remove_over_rest(): void
    {
        Mail::fake();
        $form = Form::factory()->create();
        $headers = $this->key($form->user);
        $base = "/api/v1/forms/{$form->public_id}/recipients";
        $this->postJson($base, ['email' => 'client@bakery.example'], $headers)->assertCreated();

        $this->postJson("{$base}/client@bakery.example/resend", [], $headers)->assertOk();
        Mail::assertQueued(RecipientConfirmation::class, 2);

        $this->postJson("{$base}/nobody@bakery.example/resend", [], $headers)->assertNotFound()->assertJsonStructure(['message']);
        $this->deleteJson("{$base}/CLIENT@bakery.example", [], $headers)->assertOk();
        $this->assertSame(0, $form->recipients()->count());
        $this->deleteJson("{$base}/client@bakery.example", [], $headers)->assertNotFound();
    }

    public function test_the_daily_confirmation_cap_is_shared_by_rest_and_dashboard(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $headers = $this->key($user);
        $forms = Form::factory()->count(5)->for($user)->create();

        $sent = 0;
        foreach ($forms as $form) {
            foreach (range(1, 5) as $n) {
                $response = $this->postJson("/api/v1/forms/{$form->public_id}/recipients", ['email' => "c{$n}@f{$form->id}.example"], $headers);
                $sent += $response->status() === 201 ? 1 : 0;
            }
        }

        $this->assertSame(20, $sent);
        Mail::assertQueued(RecipientConfirmation::class, 20);
    }

    public function test_patch_changes_the_email_settings_and_refuses_unknown_fields(): void
    {
        $form = Form::factory()->create();
        $headers = $this->key($form->user);
        $url = "/api/v1/forms/{$form->public_id}";

        $this->patchJson($url, ['sender_name' => ' Studio Noord ', 'notify_owner' => false], $headers)
            ->assertOk()->assertJsonPath('data.sender_name', 'Studio Noord')->assertJsonPath('data.notify_owner', false);

        $this->patchJson($url, ['sender_name' => 'Evil <x@y.z>'], $headers)->assertUnprocessable()->assertJsonValidationErrors('sender_name');

        // An unknown field refuses the whole change, so the valid name next to it is not saved either.
        $this->patchJson($url, ['name' => 'Renamed', 'webhook_secret' => 'mine'], $headers)->assertUnprocessable()
            ->assertJsonPath('message', fn (string $m) => str_starts_with($m, 'PATCH does not change webhook_secret. It changes: name, site_url,'));
        $this->assertSame('Contact form', $form->fresh()->name);

        $this->patchJson($url, ['sender_name' => ''], $headers)->assertOk()->assertJsonPath('data.sender_name', null);
    }

    public function test_one_account_cannot_touch_another_accounts_recipients(): void
    {
        $theirs = Form::factory()->create();
        $theirs->recipients()->create(['email' => 'x@example.org']);
        $headers = $this->key(User::factory()->create());

        $this->postJson("/api/v1/forms/{$theirs->public_id}/recipients", ['email' => 'me@evil.example'], $headers)->assertNotFound();
        $this->deleteJson("/api/v1/forms/{$theirs->public_id}/recipients/x@example.org", [], $headers)->assertNotFound();
        $this->patchJson("/api/v1/forms/{$theirs->public_id}", ['notify_owner' => false], $headers)->assertNotFound();
        $this->assertSame(1, $theirs->recipients()->count());
        $this->assertTrue($theirs->fresh()->notify_owner);
    }

    public function test_mcp_adds_resends_lists_updates_and_removes(): void
    {
        Mail::fake();
        $form = Form::factory()->create();
        $user = $form->user;
        $id = $form->public_id;

        FormHavenServer::actingAs($user)->tool(AddRecipientTool::class, ['form_id' => $id, 'email' => 'client@bakery.example'])
            ->assertOk()->assertSee('We emailed client@bakery.example a confirmation link.');
        FormHavenServer::actingAs($user)->tool(AddRecipientTool::class, ['form_id' => $id, 'email' => 'client@bakery.example'])
            ->assertOk()->assertSee('Sent the confirmation link to client@bakery.example again.');
        Mail::assertQueued(RecipientConfirmation::class, 2);

        FormHavenServer::actingAs($user)->tool(AddRecipientTool::class, ['form_id' => $id, 'email' => $user->email])
            ->assertHasErrors(['That is your own address. Use "Email me too" (notify_owner) instead.']);
        FormHavenServer::actingAs($user)->tool(ListFormsTool::class)->assertOk()->assertSee('client@bakery.example');

        FormHavenServer::actingAs($user)->tool(UpdateFormEmailsTool::class, ['form_id' => $id, 'sender_name' => 'Studio Noord', 'notify_owner' => false])
            ->assertOk()->assertSee('Studio Noord');
        $this->assertFalse($form->fresh()->notify_owner);
        FormHavenServer::actingAs($user)->tool(UpdateFormEmailsTool::class, ['form_id' => $id, 'sender_name' => "Two\nlines"])
            ->assertHasErrors(['Leave out < > " and backslashes.']);

        FormHavenServer::actingAs($user)->tool(RemoveRecipientTool::class, ['form_id' => $id, 'email' => 'client@bakery.example'])->assertOk();
        FormHavenServer::actingAs($user)->tool(RemoveRecipientTool::class, ['form_id' => $id, 'email' => 'client@bakery.example'])
            ->assertHasErrors(['This form does not email client@bakery.example. list_forms shows each form\'s recipients.']);
        FormHavenServer::actingAs(User::factory()->create())->tool(AddRecipientTool::class, ['form_id' => $id, 'email' => 'a@b.example'])
            ->assertHasErrors(['No form with this id on the account. Call list_forms to see the ids.']);
    }
}
