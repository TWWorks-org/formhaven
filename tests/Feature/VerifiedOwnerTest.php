<?php

namespace Tests\Feature;

use App\Actions\FormEmails;
use App\Actions\StartForm;
use App\Mail\RecipientConfirmation;
use App\Mcp\Servers\FormHavenServer;
use App\Mcp\Tools\AddRecipientTool;
use App\Models\Form;
use App\Models\FormClaim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Only an account with a confirmed address can claim a reserved form or have confirmation emails
 * sent in its name. The axis is the path: the claim page, the claim action itself, and adding or
 * resending a recipient over the dashboard action, REST and MCP.
 */
class VerifiedOwnerTest extends TestCase
{
    use RefreshDatabase;

    private function start(): array
    {
        return $this->postJson('/api/v1/start', ['name' => 'Contact form on example.nl'])->assertCreated()->json();
    }

    public function test_an_unverified_account_cannot_claim_and_is_told_to_confirm_first(): void
    {
        $started = $this->start();
        $user = User::factory()->unverified()->create(['email' => 'new@studio.example']);

        $this->actingAs($user)->get($started['data']['claim_url'])->assertOk()
            ->assertSee('First confirm your email address')
            ->assertSee('We sent a link to new@studio.example. Press it, and you come back to this page to press Activate.')
            ->assertDontSee('Activate this form</button>', false);

        $this->actingAs($user)->post($started['data']['claim_url'])->assertRedirect(route('verification.notice'));

        $this->assertDatabaseCount('forms', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull(FormClaim::sole()->claimed_at);
    }

    public function test_the_claim_action_itself_refuses_an_unverified_account(): void
    {
        $started = $this->start();
        $user = User::factory()->unverified()->create(['email' => 'new@studio.example']);

        try {
            app(StartForm::class)->claim(FormClaim::sole(), $user);
            $this->fail('An unverified account claimed a form');
        } catch (ValidationException $e) {
            $this->assertSame('Confirm your email address first: press the link we sent to new@studio.example, then press Activate again.', $e->errors()['claim'][0]);
        }

        $this->assertDatabaseCount('forms', 0);
    }

    public function test_control_a_verified_account_claims_as_before(): void
    {
        $started = $this->start();
        $user = User::factory()->create();

        $this->actingAs($user)->get($started['data']['claim_url'])->assertSee('Activate this form');
        $this->actingAs($user)->post($started['data']['claim_url'])->assertRedirect(route('forms.show', $user->forms()->sole()));
    }

    /** An account claimed before this rule, with a setup key, whose address was never confirmed. */
    private function unverifiedFormWithKey(): array
    {
        $owner = User::factory()->unverified()->create(['email' => 'owner@studio.example']);
        $form = Form::factory()->for($owner)->create();
        $key = $owner->createToken('Agent setup: old', [User::FORM_ABILITY.$form->public_id])->plainTextToken;

        return [$form, ['Authorization' => 'Bearer '.$key]];
    }

    public function test_no_path_sends_a_confirmation_email_for_an_unverified_owner(): void
    {
        Mail::fake();
        [$form, $key] = $this->unverifiedFormWithKey();
        $refusal = 'Confirm your own email address first: press the link we sent to owner@studio.example, or send it again from Settings. Then add this address.';

        // REST
        $this->postJson("/api/v1/forms/{$form->public_id}/recipients", ['email' => 'client@example.net'], $key)
            ->assertUnprocessable()->assertJsonPath('errors.email.0', $refusal);

        // MCP
        FormHavenServer::actingAs($form->user)->tool(AddRecipientTool::class, ['form_id' => $form->public_id, 'email' => 'client@example.net'])
            ->assertHasErrors([$refusal]);

        // The shared action the dashboard calls, and resend for an address added before.
        try {
            app(FormEmails::class)->add($form, 'other@example.net');
            $this->fail('Added a recipient for an unverified owner');
        } catch (ValidationException $e) {
            $this->assertSame($refusal, $e->errors()['email'][0]);
        }
        $old = $form->recipients()->create(['email' => 'waiting@example.net']);
        $this->expectException(ValidationException::class);
        try {
            app(FormEmails::class)->resend($old);
        } finally {
            $this->assertSame(1, $form->recipients()->count(), 'only the address added before this test step');
            Mail::assertNothingQueued();
        }
    }

    public function test_control_a_verified_owner_still_adds_a_recipient(): void
    {
        Mail::fake();
        $form = Form::factory()->create();

        app(FormEmails::class)->add($form, 'client@example.net');

        Mail::assertQueued(RecipientConfirmation::class, fn ($mail) => $mail->hasTo('client@example.net'));
    }
}
