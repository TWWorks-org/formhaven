<?php

namespace Tests\Feature;

use App\Mcp\Servers\FormHavenServer;
use App\Models\Form;
use App\Models\FormClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** An agent with no account reserves a form; the site owner switches it on with one click. */
class StartFlowTest extends TestCase
{
    use RefreshDatabase;

    private function start(array $input = ['name' => 'Contact form on example.nl', 'site_url' => 'https://example.nl', 'client' => 'Claude Code']): array
    {
        return $this->postJson('/api/v1/start', $input)->assertCreated()->json();
    }

    private function claimToken(array $started): string
    {
        return basename(parse_url($started['data']['claim_url'], PHP_URL_PATH));
    }

    public function test_a_reserved_form_stores_nothing_until_the_owner_activates_it(): void
    {
        $started = $this->start();

        $this->assertSame('waiting_for_owner', $started['data']['status']);
        $this->assertStringStartsWith('fhs_', $started['data']['setup_key']);
        $this->assertStringContainsString($started['data']['endpoint'], $started['html']);
        $this->assertStringContainsString('claim_url', $started['next']);

        $this->postJson($started['data']['endpoint'], ['email' => 'visitor@example.com', 'message' => 'Hello'])
            ->assertStatus(403)->assertJsonPath('ok', false)->assertJsonPath('errors.0.code', 'FORM_NOT_ACTIVE');

        $this->assertDatabaseCount('submissions', 0);
        $this->assertDatabaseCount('forms', 0);
    }

    public function test_a_guest_registers_from_the_link_activates_and_the_setup_key_then_works(): void
    {
        Mail::fake();
        $started = $this->start();
        $key = ['Authorization' => 'Bearer '.$started['data']['setup_key']];
        $claimUrl = $started['data']['claim_url'];

        // Before activation the key opens nothing.
        $this->getJson('/api/v1/forms', $key)->assertUnauthorized();
        $this->getJson('/api/v1/start/'.$started['data']['id'], $key)->assertOk()->assertJsonPath('data.status', 'waiting_for_owner');

        $this->get($claimUrl)->assertOk()->assertSee('Activate "Contact form on example.nl"')->assertSee('Create an account')->assertSee('The agent called itself')->assertSee('Claude Code');

        Volt::test('auth.register')
            ->set('name', 'Studio Noord')->set('email', 'hello@studionoord.example')
            ->set('password', 'a-long-password-1')->set('password_confirmation', 'a-long-password-1')
            ->set('terms', true)
            ->call('register')
            ->assertRedirect($claimUrl);

        $user = User::where('email', 'hello@studionoord.example')->sole();
        // A new account confirms its address first; the link in that email brings it back here.
        $this->actingAs($user)->get($claimUrl)->assertOk()->assertSee('First confirm your email address')->assertDontSee('Activate this form</button>', false);
        $this->actingAs($user)->post($claimUrl)->assertRedirect(route('verification.notice'));
        $this->assertSame(0, $user->forms()->count());

        $verify = \Illuminate\Support\Facades\URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->actingAs($user)->get($verify)->assertRedirect($claimUrl);
        $this->actingAs($user->fresh())->get($claimUrl)->assertSee('Activate this form');
        $this->actingAs($user->fresh())->post($claimUrl)->assertRedirect();

        $form = $user->forms()->sole();
        $this->assertSame($started['data']['id'], $form->public_id);
        $this->assertSame('https://example.nl', $form->site_url);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/start/'.$form->public_id, $key)->assertJsonPath('data.status', 'active');
        $this->getJson('/api/v1/forms', $key)->assertOk()->assertJsonPath('data.0.id', $form->public_id);
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $key)->assertOk()->assertSee('get_privacy_text');

        $this->postJson($started['data']['endpoint'], ['email' => 'visitor@example.com', 'message' => 'Hello'])->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(1, $form->submissions()->count());
    }

    public function test_the_setup_key_reaches_only_its_own_form_on_an_account_with_other_forms(): void
    {
        // Someone starts a form and sends the link to an existing customer, who presses Activate.
        $customer = User::factory()->create();
        $clientForm = Form::factory()->for($customer)->create(['name' => 'Client site']);
        $clientForm->submissions()->create(['data' => ['message' => 'Private client enquiry']]);
        $started = $this->start();
        $this->actingAs($customer)->post($started['data']['claim_url'])->assertRedirect();
        $this->app['auth']->forgetGuards();

        $key = ['Authorization' => 'Bearer '.$started['data']['setup_key']];
        $mcp = fn (string $tool, array $arguments = []) => $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]], $key);

        $this->getJson('/api/v1/forms', $key)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $started['data']['id']);
        $this->getJson("/api/v1/forms/{$clientForm->public_id}", $key)->assertNotFound();
        $this->getJson("/api/v1/forms/{$clientForm->public_id}/submissions", $key)->assertNotFound();
        $this->patchJson("/api/v1/forms/{$clientForm->public_id}", ['webhook_url' => 'https://attacker.example/hook'], $key)->assertNotFound();
        $this->postJson('/api/v1/forms', ['name' => 'Another'], $key)->assertForbidden()
            ->assertJsonPath('message', \App\Actions\CreateForm::SCOPED_KEY_REFUSAL);

        $mcp('list_forms')->assertOk()->assertSee($started['data']['id'])->assertDontSee($clientForm->public_id);
        $mcp('list_submissions', ['form_id' => $clientForm->public_id])->assertOk()->assertDontSee('Private client enquiry');
        $mcp('create_form', ['name' => 'Another'])->assertOk()->assertSee('This key belongs to one form');

        $this->assertNull($clientForm->fresh()->webhook_url);
        $this->assertSame(2, $customer->forms()->count());

        // The customer's own dashboard key still reaches everything.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/forms', ['Authorization' => 'Bearer '.$customer->createToken('mine')->plainTextToken])->assertJsonCount(2, 'data');
    }

    public function test_an_ended_trial_sees_the_billing_message_and_no_form_is_made(): void
    {
        $started = $this->start();
        $expired = User::factory()->create(['trial_ends_at' => now()->subDay()]);

        $this->actingAs($expired)->from($started['data']['claim_url'])->post($started['data']['claim_url'])
            ->assertRedirect($started['data']['claim_url'])
            ->assertSessionHasErrors(['claim' => 'Your trial has ended. Subscribe on the Billing page to create forms.']);

        $this->assertDatabaseCount('forms', 0);
        $this->assertNull(FormClaim::sole()->claimed_at);
    }

    public function test_a_claimed_link_cannot_be_claimed_again_by_someone_else(): void
    {
        $started = $this->start();
        $owner = User::factory()->create();
        $this->actingAs($owner)->post($started['data']['claim_url']);

        $other = User::factory()->create();
        $this->actingAs($other)->post($started['data']['claim_url'])->assertOk()->assertSee('This form is already active');
        $this->assertSame(0, $other->forms()->count());

        // The owner opening it again goes to the form.
        $this->actingAs($owner)->get($started['data']['claim_url'])->assertRedirect(route('forms.show', $owner->forms()->sole()));
    }

    public function test_a_lapsed_reservation_is_pruned_and_its_address_stops_answering(): void
    {
        $started = $this->start();
        $this->travel(FormClaim::DAYS + 1)->days();

        $this->get($started['data']['claim_url'])->assertNotFound()->assertSee('This activation link has expired');
        $this->postJson($started['data']['endpoint'], ['message' => 'Hi'])->assertNotFound()->assertJsonPath('errors.0.code', 'FORM_NOT_FOUND');
        $this->getJson('/api/v1/start/'.$started['data']['id'], ['Authorization' => 'Bearer '.$started['data']['setup_key']])->assertStatus(410);

        $this->artisan('model:prune', ['--model' => [FormClaim::class]])->assertSuccessful();
        $this->assertDatabaseCount('form_claims', 0);
    }

    public function test_start_refuses_bad_input_and_too_many_starts_from_one_address(): void
    {
        $this->postJson('/api/v1/start', [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/start', ['name' => str_repeat('x', 101)])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/start', ['name' => 'X', 'site_url' => 'javascript:alert(1)'])->assertUnprocessable()->assertJsonValidationErrors('site_url');

        // The three refused attempts above count toward the 10 an hour as well.
        for ($i = 0; $i < 7; $i++) {
            $this->postJson('/api/v1/start', ['name' => "Form {$i}"])->assertCreated();
        }
        $this->postJson('/api/v1/start', ['name' => 'One more'])->assertStatus(429)
            ->assertJsonPath('message', 'Too many forms started from this address. Try again in an hour, or create the form with an API key.');
    }

    public function test_the_status_route_needs_the_right_key(): void
    {
        $started = $this->start();

        $this->getJson('/api/v1/start/'.$started['data']['id'])->assertUnauthorized();
        $this->getJson('/api/v1/start/'.$started['data']['id'], ['Authorization' => 'Bearer fhs_wrong'])->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated. Send the setup_key from POST /api/v1/start as Authorization: Bearer.');
    }

    public function test_the_agents_report_counts_starts_and_activations(): void
    {
        $started = $this->start();
        $this->start(['name' => 'Second']);
        $this->actingAs(User::factory()->create())->post($started['data']['claim_url']);
        Form::where('public_id', $started['data']['id'])->sole()->submissions()->create(['data' => ['m' => 'Hi']]);

        $this->artisan('agents:report')
            ->expectsOutputToContain('Started:   2')
            ->expectsOutputToContain('Activated: 1 (50%)')
            ->expectsOutputToContain('Activated and got a real message: 1')
            ->expectsOutputToContain('Claude Code: 1')
            ->assertSuccessful();
    }
}
