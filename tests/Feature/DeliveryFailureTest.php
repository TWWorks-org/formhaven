<?php

namespace Tests\Feature;

use App\Actions\RetryDeliveries;
use App\Mail\DeliveryProblem;
use App\Mail\DeliveryRefused;
use App\Mail\RecipientConfirmation;
use App\Mail\SubmissionReceived;
use App\Mcp\Servers\FormHavenServer;
use App\Mcp\Tools\ListFormsTool;
use App\Mcp\Tools\RetryDeliveriesTool;
use App\Models\DeliveryFailure;
use App\Models\Form;
use App\Models\FormRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * An email that did not arrive is recorded, the owner hears of it, and Send again delivers it.
 * The axis is whose address fails (the owner's, a recipient's, a confirmation link) and what the
 * form keeps (everything or nothing), so each case below varies one of those.
 */
class DeliveryFailureTest extends TestCase
{
    use RefreshDatabase;

    private function useCloudflare(array $result): void
    {
        config([
            'mail.default' => 'cloudflare',
            'mail.from.address' => 'hello@forms.example.test',
            'mail.from.name' => 'FormHaven',
            'services.cloudflare_email.account_id' => 'acc123',
            'services.cloudflare_email.token' => 'tok',
        ]);
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => $result])]);
    }

    private function recipient(Form $form, string $email = 'client@example.net'): FormRecipient
    {
        $recipient = $form->recipients()->create(['email' => $email]);
        $recipient->forceFill(['confirmed_at' => now()])->save();

        return $recipient;
    }

    private function submission(Form $form)
    {
        $submission = $form->submissions()->create(['data' => ['name' => 'Anna', 'email' => 'anna@example.org', 'message' => 'Hoi']]);
        $submission->forceFill(['emails_pending' => 1])->save();

        return $submission;
    }

    /** Sends through the real transport on the sync queue, which calls failed() and then rethrows. */
    private function sendAndSwallow(callable $send): void
    {
        try {
            $send();
        } catch (\Throwable) {
        }
    }

    public function test_a_bounced_copy_to_a_recipient_is_recorded_and_the_message_kept(): void
    {
        $this->useCloudflare(['permanent_bounces' => ['client@example.net']]);
        $form = Form::factory()->create(['store_submissions' => false]);
        $recipient = $this->recipient($form);
        $submission = $this->submission($form);

        $this->sendAndSwallow(fn () => Mail::to($recipient->email)->queue(new SubmissionReceived($submission, $recipient)));

        $failure = DeliveryFailure::sole();
        $this->assertSame('bounced', $failure->kind);
        $this->assertSame($recipient->id, $failure->form_recipient_id);
        $this->assertSame('client@example.net', $failure->email);
        $this->assertSame(1, $submission->fresh()->emails_pending, 'A failed send is not counted as done');
        $this->assertFalse($submission->fresh()->isForgotten(), 'A form that keeps nothing must keep a message that was not delivered');
    }

    public function test_the_owners_own_address_failing_is_recorded_but_not_emailed_about(): void
    {
        $form = Form::factory()->create();
        $submission = $this->submission($form);
        Mail::fake();

        (new SubmissionReceived($submission))->failed(new DeliveryRefused('suppressed', DeliveryRefused::SUPPRESSED));

        $this->assertNull(DeliveryFailure::sole()->form_recipient_id);
        $this->assertSame($form->user->email, DeliveryFailure::sole()->email);
        Mail::assertNothingQueued();
    }

    public function test_a_recipient_removed_while_the_job_waited_leaves_nothing(): void
    {
        $form = Form::factory()->create();
        $recipient = $this->recipient($form);
        $job = new SubmissionReceived($this->submission($form), $recipient);
        $recipient->delete();

        $job->failed(new DeliveryRefused('bounced', DeliveryRefused::BOUNCED));

        $this->assertSame(0, DeliveryFailure::count());
    }

    public function test_a_confirmation_link_that_bounced_is_recorded_without_a_submission(): void
    {
        $this->useCloudflare(['permanent_bounces' => ['clinet@example.net']]);
        $form = Form::factory()->create();
        $recipient = $form->recipients()->create(['email' => 'clinet@example.net']);

        $this->sendAndSwallow(fn () => Mail::to($recipient->email)->queue(new RecipientConfirmation($recipient)));

        $failure = DeliveryFailure::sole();
        $this->assertNull($failure->submission_id);
        $this->assertSame('CONFIRMATION_FAILED', $form->fresh()->health()['problems'][0]['code']);
    }

    public function test_a_failure_goes_with_its_submission_its_recipient_and_its_form(): void
    {
        $form = Form::factory()->create();
        $recipient = $this->recipient($form);
        $record = fn (FormRecipient $to) => DeliveryFailure::record($form, $this->submission($form), $to, $to->email, new \RuntimeException('down'));

        $record($recipient)->submission->delete();
        $this->assertSame(0, DeliveryFailure::count(), 'pruned submission');

        $record($recipient);
        $recipient->delete();
        $this->assertSame(0, DeliveryFailure::count(), 'removed recipient');

        $record($this->recipient($form));
        $form->delete();
        $this->assertSame(0, DeliveryFailure::count(), 'deleted form');
    }

    public function test_the_owner_is_told_once_a_day_per_address(): void
    {
        Mail::fake();
        $form = Form::factory()->create();
        $client = $this->recipient($form);
        $other = $this->recipient($form, 'other@example.net');
        $fail = fn (FormRecipient $to) => (new SubmissionReceived($this->submission($form), $to))->failed(new DeliveryRefused('bounced', DeliveryRefused::BOUNCED));

        $fail($client);
        $fail($client);
        Mail::assertQueued(DeliveryProblem::class, 1);
        Mail::assertQueued(DeliveryProblem::class, fn (DeliveryProblem $mail) => $mail->hasTo($form->user->email) && $mail->email === 'client@example.net' && $mail->waiting === 1);

        $fail($other);
        Mail::assertQueued(DeliveryProblem::class, 2);

        $this->travel(25)->hours();
        $fail($client);
        Mail::assertQueued(DeliveryProblem::class, 3);
    }

    public function test_the_log_line_keeps_the_reason_and_drops_the_address(): void
    {
        Mail::fake();
        \Illuminate\Support\Facades\Log::spy();
        $form = Form::factory()->create();

        (new SubmissionReceived($this->submission($form), $this->recipient($form)))
            ->failed(new DeliveryRefused('Cloudflare Email Service permanently bounced: client@example.net', DeliveryRefused::BOUNCED));

        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $context['error'] === 'Cloudflare Email Service permanently bounced: [address]'
            && ! str_contains(json_encode($context), 'example.nl'));
    }

    public function test_an_owner_who_never_verified_their_address_is_not_emailed(): void
    {
        Mail::fake();
        $form = Form::factory()->for(User::factory()->unverified())->create();

        (new SubmissionReceived($this->submission($form), $this->recipient($form)))->failed(new DeliveryRefused('bounced', DeliveryRefused::BOUNCED));

        Mail::assertNothingQueued();
    }

    public function test_the_alert_says_what_failed_that_nothing_is_lost_and_what_to_do(): void
    {
        $mail = new DeliveryProblem('Contact <b>', 'https://formhaven.test/forms/1', 'client@example.net', 'The mail server refused it.', 'Check the address.', 3, false);

        $html = $mail->render();
        $this->assertStringContainsString('A message did not reach client@example.net', html_entity_decode($html));
        $this->assertStringContainsString('3 messages for this address are', $html);
        $this->assertStringContainsString('Check the address.', $html);
        $this->assertStringNotContainsString('<b>', $html, 'A form name is shown as typed, never as markup');
        $mail->assertHasSubject('Messages from Contact <b> are not reaching client@example.net');
    }

    public function test_send_again_delivers_clears_the_failure_and_then_forgets_a_keep_nothing_message(): void
    {
        $this->useCloudflare([]);
        $form = Form::factory()->create(['store_submissions' => false]);
        $recipient = $this->recipient($form);
        $submission = $this->submission($form);
        DeliveryFailure::record($form, $submission, $recipient, $recipient->email, new DeliveryRefused('bounced', DeliveryRefused::BOUNCED));

        $result = app(RetryDeliveries::class)->handle($form);

        $this->assertSame(['queued' => 1, 'dropped' => 0, 'left' => 0, 'limit_reached' => null], $result);
        $this->assertSame(0, DeliveryFailure::count());
        $this->assertTrue($submission->fresh()->isForgotten(), 'Delivered at last, so the form keeps nothing again');
        Http::assertSent(fn ($request) => $request['to'] === ['client@example.net']);
    }

    public function test_send_again_that_fails_again_leaves_one_failure_not_two(): void
    {
        $this->useCloudflare(['permanent_bounces' => ['client@example.net']]);
        $form = Form::factory()->create();
        $recipient = $this->recipient($form);
        DeliveryFailure::record($form, $this->submission($form), $recipient, $recipient->email, new DeliveryRefused('bounced', DeliveryRefused::BOUNCED));

        app(RetryDeliveries::class)->handle($form);

        $this->assertSame(1, DeliveryFailure::count());
    }

    public function test_a_message_for_an_address_that_was_unconfirmed_since_is_dropped_and_counted_as_done(): void
    {
        Mail::fake();
        $form = Form::factory()->create(['store_submissions' => false]);
        $recipient = $this->recipient($form);
        $submission = $this->submission($form);
        DeliveryFailure::record($form, $submission, $recipient, $recipient->email, new \RuntimeException('down'));
        $recipient->forceFill(['confirmed_at' => null])->save();

        $this->assertSame(['queued' => 0, 'dropped' => 1, 'left' => 0, 'limit_reached' => null], app(RetryDeliveries::class)->handle($form));
        $this->assertTrue($submission->fresh()->isForgotten());
        Mail::assertNothingQueued();
    }

    public function test_send_again_is_limited_to_three_runs_an_hour(): void
    {
        Mail::fake();
        $form = Form::factory()->create();
        $retry = app(RetryDeliveries::class);

        foreach (range(1, RetryDeliveries::RUNS_PER_HOUR) as $run) {
            $retry->handle($form);
        }

        $this->expectException(ValidationException::class);
        $retry->handle($form);
    }

    public function test_rest_and_mcp_show_the_same_health_and_both_can_send_again(): void
    {
        Mail::fake();
        $form = Form::factory()->create();
        $recipient = $this->recipient($form);
        $submission = $this->submission($form);
        DeliveryFailure::record($form, $submission, $recipient, $recipient->email, new DeliveryRefused('suppressed', DeliveryRefused::SUPPRESSED));
        $headers = ['Authorization' => 'Bearer '.$form->user->createToken('t')->plainTextToken];

        $rest = $this->getJson('/api/v1/forms', $headers)->assertOk()->json('data.0');
        $this->assertSame('attention', $rest['health']['status']);
        $this->assertSame('UNDELIVERED', $rest['health']['problems'][0]['code']);
        $this->assertStringContainsString('1 message did not reach client@example.net', $rest['health']['problems'][0]['message']);
        $this->assertNotNull($rest['recipients'][0]['delivery_problem']);
        $this->assertSame('client@example.net', $this->getJson("/api/v1/forms/{$form->public_id}/submissions", $headers)->json('data.0.undelivered.0.email'));

        FormHavenServer::actingAs($form->user)->tool(ListFormsTool::class)
            ->assertOk()->assertSee(['"status":"attention"', 'UNDELIVERED', $rest['health']['problems'][0]['fix']]);

        $this->postJson("/api/v1/forms/{$form->public_id}/deliveries/retry", [], $headers)
            ->assertOk()->assertJsonPath('data.queued', 1)->assertJsonPath('data.left', 0);
        Mail::assertQueued(SubmissionReceived::class, fn ($mail) => $mail->hasTo('client@example.net'));

        FormHavenServer::actingAs($form->user)->tool(RetryDeliveriesTool::class, ['form_id' => $form->public_id])
            ->assertOk()->assertSee('Nothing is waiting to be sent again.');
    }

    public function test_nobody_sends_again_for_another_account(): void
    {
        $form = Form::factory()->create();
        $stranger = User::factory()->create();

        $this->postJson("/api/v1/forms/{$form->public_id}/deliveries/retry", [], ['Authorization' => 'Bearer '.$stranger->createToken('t')->plainTextToken])->assertNotFound();
        FormHavenServer::actingAs($stranger)->tool(RetryDeliveriesTool::class, ['form_id' => $form->public_id])
            ->assertHasErrors(['No form with this id on the account. Call list_forms to see the ids.']);
    }

    public function test_the_dashboard_flags_the_form_shows_why_and_sends_again(): void
    {
        Mail::fake();
        $form = Form::factory()->create(['name' => 'Contact on example.nl']);
        $healthy = Form::factory()->for($form->user)->create(['name' => 'Quote form']);
        $recipient = $this->recipient($form);
        DeliveryFailure::record($form, $this->submission($form), $recipient, $recipient->email, new DeliveryRefused('bounced', DeliveryRefused::BOUNCED));
        $this->actingAs($form->user);

        Volt::test('forms.index')->set('status', 'attention')
            ->assertSee('Contact on example.nl')->assertDontSee('Quote form')->assertSee('Needs attention');

        Volt::test('forms.show', ['form' => $form])
            ->assertSee('1 message did not reach client@example.net')
            ->assertSee('Not delivered to client@example.net')
            ->call('retryDeliveries')
            ->assertSee('Sent 1 message again.')
            ->assertDontSee('1 message did not reach client@example.net');

        Volt::test('forms.show', ['form' => $healthy])->assertDontSee('need a look');
    }

    public function test_an_unconfirmed_recipient_and_an_unverified_owner_are_problems_too(): void
    {
        $form = Form::factory()->for(User::factory()->unverified())->create();
        $form->recipients()->create(['email' => 'late@example.net'])->forceFill(['created_at' => now()->subDays(Form::UNCONFIRMED_DAYS + 1)])->save();
        $form->recipients()->create(['email' => 'new@example.net']);

        $codes = collect($form->fresh()->health()['problems'])->map(fn ($p) => $p['code'].':'.$p['email'])->all();

        $this->assertSame(['RECIPIENT_UNCONFIRMED:late@example.net', 'OWNER_UNVERIFIED:'.$form->user->email], $codes);
    }

    public function test_a_form_without_problems_reads_ok_everywhere(): void
    {
        $form = Form::factory()->create();
        $this->recipient($form);

        $this->assertSame(['status' => 'ok', 'problems' => []], $form->health());
        $this->assertNotSame('attention', $form->dashboardStatus(null));
    }

    public function test_llms_txt_explains_every_health_code(): void
    {
        $llms = $this->get('/llms.txt')->assertOk()->getContent();

        foreach (Form::HEALTH_CODES as $code) {
            $this->assertStringContainsString($code.':', $llms, "llms.txt does not explain {$code}");
        }
    }

    public function test_the_privacy_text_of_a_keep_nothing_form_says_an_undelivered_message_is_kept(): void
    {
        $form = Form::factory()->create(['store_submissions' => false, 'retention_days' => 14]);

        $en = \App\Support\PrivacyText::for($form, 'en');

        $this->assertStringContainsString('If that email cannot be delivered, it keeps them for at most 14 days', $en['paragraph']);
        $this->assertSame(14, $en['facts']['kept_if_undelivered_days']);
    }
}
