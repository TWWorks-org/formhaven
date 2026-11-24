<?php

namespace Tests\Feature;

use App\Actions\RetryDeliveries;
use App\Mail\AbuseReportFiled;
use App\Mail\AbuseReportReceived;
use App\Mail\MailBudgetSpent;
use App\Mail\MailLimitReached;
use App\Mail\RecipientSummary;
use App\Mail\SubmissionReceived;
use App\Mail\WeeklyDigest;
use App\Models\AbuseReport;
use App\Models\DeliveryFailure;
use App\Models\Form;
use App\Models\FormRecipient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The daily mail budgets. The axis is how many emails one message costs (one target or several),
 * whose forms share a pool (one form, several forms of one owner, forms of different owners), where
 * the count sits against its limit (at it, one over) and which UTC day it is. Every case looks at
 * what a person sees: the visitor's JSON, the emails queued and the form's health block.
 */
class MailBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutMiddleware(ThrottleRequests::class);
        Carbon::setTestNow('2026-11-24 10:00:00');
        config([
            'mail.budget.notifications_per_day' => 100,
            'mail.budget.per_form_per_day' => 100,
            'mail.budget.notices_per_day' => 10,
            'billing.owner_emails' => ['operator@formhaven.test'],
        ]);
    }

    private function submit(Form $form, string $message = 'Hello')
    {
        return $this->postJson("/f/{$form->public_id}", ['email' => 'visitor@example.org', 'message' => $message])
            ->assertOk()->assertJson(['ok' => true]);
    }

    private function recipients(Form $form, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $form->recipients()->create(['email' => "client{$i}@example.net"])->forceFill(['confirmed_at' => now()])->save();
        }
    }

    private function health(Form $form): array
    {
        return $form->fresh()->load(['recipients', 'deliveryFailures'])->health();
    }

    public function test_a_copied_address_stops_at_the_form_limit_and_the_visitor_still_gets_success(): void
    {
        config(['mail.budget.per_form_per_day' => 3]);
        $form = Form::factory()->create(['name' => 'Bakker contact']);

        foreach (range(1, 5) as $i) {
            $this->submit($form, "Flood {$i}");
        }

        $this->assertSame(5, $form->submissions()->count(), 'every message is stored');
        Mail::assertQueued(SubmissionReceived::class, 3);

        $health = $this->health($form);
        $this->assertSame('attention', $health['status']);
        $this->assertSame('UNDELIVERED', $health['problems'][0]['code']);
        $this->assertSame('2 messages did not reach '.$form->user->email.'. This form reached its limit of 3 emails a day, so we held the email instead of sending it.', $health['problems'][0]['message']);
        $this->assertStringStartsWith('Press Send again after 00:00 UTC', $health['problems'][0]['fix']);

        // The owner hears of it once that day, however many more are held.
        Mail::assertQueued(MailLimitReached::class, 1);
        Mail::assertQueued(MailLimitReached::class, fn ($mail) => $mail->hasTo($form->user->email) && $mail->limit === 3);
        Mail::assertNotQueued(MailBudgetSpent::class);

        // The dashboard shows the same block with its Send again button.
        $this->actingAs($form->user)->get(route('forms.show', $form))
            ->assertSee('This form reached its limit of 3 emails a day')->assertSee('Send again');
    }

    public function test_a_message_to_five_recipients_costs_five_and_only_the_overflow_is_held(): void
    {
        config(['mail.budget.per_form_per_day' => 7]);
        $form = Form::factory()->create(['notify_owner' => false]);
        $this->recipients($form, 5);

        $this->submit($form, 'First');
        Mail::assertQueued(SubmissionReceived::class, 5);
        $this->assertSame(0, DeliveryFailure::count());

        $this->submit($form, 'Second');
        Mail::assertQueued(SubmissionReceived::class, 7);
        $held = DeliveryFailure::orderBy('id')->pluck('email')->all();
        $this->assertSame(['client3@example.net', 'client4@example.net', 'client5@example.net'], $held);
        $this->assertTrue(DeliveryFailure::all()->every(fn ($f) => $f->kind === DeliveryFailure::HELD_FORM));
        $this->assertCount(3, $this->health($form)['problems']);
    }

    public function test_several_forms_of_one_owner_each_have_their_own_limit(): void
    {
        config(['mail.budget.per_form_per_day' => 2]);
        $owner = User::factory()->create();
        $busy = Form::factory()->for($owner)->create(['name' => 'Busy']);
        $quiet = Form::factory()->for($owner)->create(['name' => 'Quiet']);

        foreach (range(1, 3) as $i) {
            $this->submit($busy);
        }
        foreach (range(1, 2) as $i) {
            $this->submit($quiet);
        }

        Mail::assertQueued(SubmissionReceived::class, 4);
        $this->assertSame('attention', $this->health($busy)['status']);
        $this->assertSame('ok', $this->health($quiet)['status']);
        Mail::assertQueued(MailLimitReached::class, 1);
        Mail::assertQueued(MailLimitReached::class, fn ($mail) => $mail->formName === 'Busy');
    }

    public function test_the_shared_limit_holds_across_owners_and_tells_the_operator_once_with_the_top_form(): void
    {
        config(['mail.budget.notifications_per_day' => 3]);
        $first = Form::factory()->create(['name' => 'Flooded form']);
        $second = Form::factory()->create(['name' => 'Innocent form']);

        $this->submit($first);
        $this->submit($first);
        $this->submit($second);
        $this->submit($second);
        $this->submit($second);

        Mail::assertQueued(SubmissionReceived::class, 3);
        $this->assertSame([DeliveryFailure::HELD_ALL, DeliveryFailure::HELD_ALL], DeliveryFailure::pluck('kind')->all());
        $this->assertStringContainsString('FormHaven reached its daily email limit for all forms', $this->health($second)['problems'][0]['message']);
        $this->assertSame('ok', $this->health($first)['status'], 'the first form sent all of its mail before the pool ran out');

        Mail::assertQueued(MailBudgetSpent::class, 1);
        Mail::assertQueued(MailBudgetSpent::class, fn ($mail) => $mail->hasTo('operator@formhaven.test')
            && str_contains($mail->topForm, 'Flooded form') && $mail->topCount === 2 && $mail->limit === 3);
        // The pool running out is not a form owner's doing, so no owner gets the per-form notice.
        Mail::assertNotQueued(MailLimitReached::class);
    }

    public function test_exactly_at_the_limit_everything_goes_and_one_over_is_held(): void
    {
        config(['mail.budget.per_form_per_day' => 2]);
        $form = Form::factory()->create();

        $this->submit($form);
        $this->submit($form);
        Mail::assertQueued(SubmissionReceived::class, 2);
        $this->assertSame('ok', $this->health($form)['status']);
        Mail::assertNotQueued(MailLimitReached::class);

        $this->submit($form);
        Mail::assertQueued(SubmissionReceived::class, 2);
        $this->assertSame(1, DeliveryFailure::count());
    }

    public function test_a_new_utc_day_sends_again_and_send_again_delivers_the_held_ones(): void
    {
        config(['mail.budget.per_form_per_day' => 1]);
        $form = Form::factory()->create(['store_submissions' => false]);

        $this->submit($form, 'Sent');
        $this->submit($form, 'Held');
        $held = $form->submissions()->latest('id')->first();
        $this->assertSame(['email' => 'visitor@example.org', 'message' => 'Held'], $held->data, 'a form that keeps nothing keeps a held message');

        // Still the same day: Send again takes from the same limit and leaves it waiting.
        $result = app(RetryDeliveries::class)->handle($form);
        $this->assertSame(0, $result['queued']);
        $this->assertSame('This form reached its limit of 1 emails a day, so 1 message waits. Press Send again after 00:00 UTC, when the limit starts over.', RetryDeliveries::summary($result));
        $this->assertSame(1, DeliveryFailure::count());

        // 23:59:59 UTC is still today.
        Carbon::setTestNow('2026-11-24 23:59:59');
        $this->assertSame(0, app(RetryDeliveries::class)->handle($form)['queued']);

        Carbon::setTestNow('2026-11-25 00:00:01');
        $result = app(RetryDeliveries::class)->handle($form);
        $this->assertSame(1, $result['queued']);
        $this->assertSame('Sent 1 message again.', RetryDeliveries::summary($result));
        $this->assertSame(0, DeliveryFailure::count());
        Mail::assertQueued(SubmissionReceived::class, fn ($mail) => $mail->submission->is($held));

        // The retry used today's one email, so a new message today is held again.
        $this->submit($form, 'Next');
        $this->assertSame(1, DeliveryFailure::count());
    }

    public function test_limit_notices_are_budgeted_so_they_cannot_grow_with_the_flood(): void
    {
        config(['mail.budget.per_form_per_day' => 1, 'mail.budget.notices_per_day' => 1]);
        $a = Form::factory()->create();
        $b = Form::factory()->create();

        foreach ([$a, $a, $b, $b, $a, $b] as $form) {
            $this->submit($form);
        }

        Mail::assertQueued(MailLimitReached::class, 1);
    }

    public function test_an_unverified_owner_gets_no_limit_notice(): void
    {
        config(['mail.budget.per_form_per_day' => 0]);
        $form = Form::factory()->for(User::factory()->unverified())->create();
        $form->recipients()->create(['email' => 'client@example.net'])->forceFill(['confirmed_at' => now()])->save();

        $this->submit($form);

        $this->assertSame(1, DeliveryFailure::count());
        Mail::assertNotQueued(MailLimitReached::class);
    }

    public function test_control_spam_takes_nothing_from_the_budget(): void
    {
        config(['mail.budget.per_form_per_day' => 1]);
        $form = Form::factory()->create();

        foreach (range(1, 5) as $i) {
            $this->postJson("/f/{$form->public_id}", ['message' => 'Buy now', '_gotcha' => 'x'])->assertJson(['ok' => true]);
        }
        $this->submit($form);

        Mail::assertQueued(SubmissionReceived::class, 1);
        $this->assertSame(0, DeliveryFailure::count());
        Mail::assertNotQueued(MailLimitReached::class);
    }

    public function test_the_dashboard_test_says_the_email_was_held(): void
    {
        config(['mail.budget.per_form_per_day' => 0]);
        $form = Form::factory()->create();

        \Livewire\Volt\Volt::actingAs($form->user)->test('forms.show', ['form' => $form])
            ->call('sendTest')
            ->assertSet('testResult', 'Test saved below. No email was sent. This form reached its limit of 0 emails a day, so we held the email instead of sending it. Press Send again after 00:00 UTC, when the limit starts over. The message itself is saved in your dashboard. If the messages are spam, turn on the bot check or list your site under Allowed sites.');
    }

    public function test_summaries_take_from_their_own_budget_and_leave_the_rest_for_the_next_run(): void
    {
        config(['mail.budget.summaries_per_day' => 2]);
        Form::factory()->count(3)->create(['messages_total' => 2]);

        $this->artisan('digest:send')->expectsOutputToContain('Queued 2 weekly summaries. 1 left for the next run')->assertSuccessful();
        Mail::assertQueued(WeeklyDigest::class, 2);

        // The recipients' weekly count shares that budget: nothing is left today.
        $form = Form::factory()->create(['recipients_can_view' => true]);
        $form->recipients()->create(['email' => 'client@example.net'])->forceFill(['confirmed_at' => now()->subMonth()])->save();
        $form->submissions()->create(['data' => ['m' => 'x']])->forceFill(['created_at' => '2026-11-18 10:00:00'])->save();
        $this->artisan('digest:recipients')->expectsOutputToContain('1 left for the next run')->assertSuccessful();
        Mail::assertNotQueued(RecipientSummary::class);

        // Submissions keep their own pool: the summaries used theirs, a message still goes.
        $this->submit(Form::factory()->create());
        Mail::assertQueued(SubmissionReceived::class, 1);

        Carbon::setTestNow('2026-11-25 08:00:00');
        $this->artisan('digest:recipients')->expectsOutputToContain('Queued 1 weekly counts')->assertSuccessful();
        Mail::assertQueued(RecipientSummary::class, 1);
    }

    private function report(): \Illuminate\Testing\TestResponse
    {
        return $this->post('/report', ['url' => 'https://phish.example/login', 'reason' => 'This form collects bank passwords for a fake bank.', 'name' => 'Sam', 'email' => 'sam@example.net', 'good_faith' => '1']);
    }

    public function test_abuse_reports_are_always_stored_and_their_mail_stops_at_its_budget(): void
    {
        config(['mail.budget.abuse_reports_per_day' => 3]);

        $this->report()->assertRedirect('/report');
        $this->get('/report')->assertSee('a receipt is on its way');
        Mail::assertQueued(AbuseReportFiled::class, 1);
        Mail::assertQueued(AbuseReportReceived::class, 1);

        // One left: our copy goes, the receipt to the stranger-chosen address does not.
        $this->report();
        $second = AbuseReport::latest('id')->first();
        $this->get('/report')->assertSee('Your report is in as number '.$second->id)->assertSee('We could not email you a receipt today');
        Mail::assertQueued(AbuseReportFiled::class, 2);
        Mail::assertQueued(AbuseReportReceived::class, 1);

        $this->report();
        $this->assertSame(3, AbuseReport::count());
        Mail::assertQueued(AbuseReportFiled::class, 2);
        Mail::assertQueued(AbuseReportReceived::class, 1);
    }
}
