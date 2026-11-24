<?php

namespace Tests\Feature;

use App\Console\Commands\SendRecipientSummaries;
use App\Mail\DeliveryRefused;
use App\Mail\RecipientSummary;
use App\Mail\SubmissionReceived;
use App\Mcp\Servers\FormHavenServer;
use App\Mcp\Tools\UpdateFormEmailsTool;
use App\Models\DeliveryFailure;
use App\Models\Form;
use App\Models\FormRecipient;
use App\Models\MonthlyUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The weekly count to a form's confirmed recipients. The axis is who qualifies (the two settings,
 * confirmation, the account's state, the recipient's own stop) and what is counted (which week,
 * which form, spam or not), so each case below moves one of those and keeps the rest.
 */
class RecipientSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Monday 23 November 2026, 07:30 in Amsterdam: the counted week is 16 to 22 November.
        $this->travelTo(Carbon::parse('2026-11-23 07:30', 'Europe/Amsterdam'));
    }

    private function formWithClient(array $attributes = [], ?User $owner = null, string $email = 'client@bakery.example'): array
    {
        $form = Form::factory()->for($owner ?? User::factory()->create(['name' => 'Robin Test', 'email' => 'owner'.uniqid().'@agency.example']))
            ->create(['name' => 'Bakery contact', 'sender_name' => 'Studio Noord', 'site_url' => 'https://bakery.example', 'recipients_can_view' => true, ...$attributes]);
        $recipient = $form->recipients()->create(['email' => $email]);
        $recipient->forceFill(['confirmed_at' => now()->subMonth()])->save();

        return [$form, $recipient];
    }

    private function message(Form $form, string $at, bool $spam = false, array $data = ['message' => 'Hello']): void
    {
        $submission = $form->submissions()->create(['data' => $data, 'spam' => $spam]);
        $submission->forceFill(['created_at' => Carbon::parse($at, 'Europe/Amsterdam')])->save();
    }

    private function key(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
    }

    public function test_only_confirmed_recipients_of_forms_with_the_page_and_the_count_on_get_it(): void
    {
        Mail::fake();

        [$gets] = $this->formWithClient(email: 'gets@example.org');
        [$pageOff] = $this->formWithClient(['recipients_can_view' => false], email: 'page-off@example.org');
        [$countOff] = $this->formWithClient(['recipients_weekly_summary' => false], email: 'count-off@example.org');
        [$unconfirmed, $waiting] = $this->formWithClient(email: 'unconfirmed@example.org');
        $waiting->forceFill(['confirmed_at' => null])->save();
        [$stopped, $stopper] = $this->formWithClient(email: 'stopped@example.org');
        $stopper->forceFill(['summary_stopped_at' => now()->subDay()])->save();
        [$bounced, $dead] = $this->formWithClient(email: 'dead@example.org');
        DeliveryFailure::record($bounced, null, $dead, $dead->email, new DeliveryRefused('bounced', DeliveryRefused::BOUNCED));

        foreach ([$gets, $pageOff, $countOff, $unconfirmed, $stopped, $bounced] as $form) {
            $this->message($form, '2026-11-18 10:00');
        }

        [$quiet] = $this->formWithClient(email: 'no-messages@example.org');
        [$spamOnly] = $this->formWithClient(email: 'spam-only@example.org');
        $this->message($spamOnly, '2026-11-18 10:00', spam: true);

        $this->artisan('digest:recipients')->expectsOutputToContain('Queued 1 weekly counts to recipients for the week of 2026-11-16.')->assertSuccessful();

        Mail::assertQueued(RecipientSummary::class, 1);
        Mail::assertQueued(RecipientSummary::class, fn ($mail) => $mail->hasTo('gets@example.org') && $mail->count === 1);
    }

    public function test_a_paused_account_or_paused_emails_get_none_as_the_message_emails_do(): void
    {
        Mail::fake();

        [$ended] = $this->formWithClient(owner: User::factory()->create(['trial_ends_at' => now()->subDay()]), email: 'ended@example.org');
        $this->assertNull($ended->user->plan(), 'Precondition: the trial ended, so the form is paused');

        $overLimit = User::factory()->create();
        [$capped] = $this->formWithClient(owner: $overLimit, email: 'capped@example.org');
        MonthlyUsage::add($overLimit->id, now(), $overLimit->emailCutoff());
        $this->assertTrue($overLimit->fresh()->emailsPaused(), 'Precondition: the trial is past its emails for the month');

        foreach ([$ended, $capped] as $form) {
            $this->message($form, '2026-11-18 10:00');
        }

        $this->artisan('digest:recipients')->expectsOutputToContain('Queued 0 weekly counts to recipients for the week of 2026-11-16. 2 held: the account\'s emails are paused.')->assertSuccessful();
        Mail::assertNotQueued(RecipientSummary::class);
    }

    public function test_it_counts_last_weeks_real_messages_on_that_form_only(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        [$form] = $this->formWithClient(owner: $owner);
        [$other] = $this->formWithClient(['name' => 'Other form'], owner: $owner, email: 'other@example.org');

        $this->message($form, '2026-11-16 00:05');       // first minutes of the week, Amsterdam time
        $this->message($form, '2026-11-22 23:55');       // last minutes of it
        $this->message($form, '2026-11-19 12:00');
        $this->message($form, '2026-11-19 12:00', spam: true);
        $this->message($form, '2026-11-15 23:55');       // the week before
        $this->message($form, '2026-11-23 06:00');       // this week
        $this->message($other, '2026-11-18 10:00');

        $this->artisan('digest:recipients')->assertSuccessful();

        Mail::assertQueued(RecipientSummary::class, fn ($mail) => $mail->hasTo('client@bakery.example') && $mail->count === 3 && $mail->weekStart === '2026-11-16' && ! $mail->partial);
        Mail::assertQueued(RecipientSummary::class, fn ($mail) => $mail->hasTo('other@example.org') && $mail->count === 1);
    }

    public function test_a_recipient_confirmed_during_the_week_counts_from_then_and_one_confirmed_after_it_gets_none(): void
    {
        Mail::fake();

        [$form, $recipient] = $this->formWithClient();
        $recipient->forceFill(['confirmed_at' => Carbon::parse('2026-11-19 09:00', 'Europe/Amsterdam')])->save();
        $this->message($form, '2026-11-17 10:00');
        $this->message($form, '2026-11-20 10:00');

        [$later, $late] = $this->formWithClient(email: 'late@example.org');
        $late->forceFill(['confirmed_at' => now()->subHour()])->save();
        $this->message($later, '2026-11-18 10:00');

        $this->artisan('digest:recipients')->assertSuccessful();

        Mail::assertQueued(RecipientSummary::class, 1);
        Mail::assertQueued(RecipientSummary::class, fn ($mail) => $mail->hasTo('client@bakery.example') && $mail->count === 1);
    }

    public function test_a_week_is_sent_once_and_the_next_week_is_sent_again(): void
    {
        Mail::fake();

        [$form, $recipient] = $this->formWithClient();
        $this->message($form, '2026-11-18 10:00');

        $this->artisan('digest:recipients')->assertSuccessful();
        $this->travel(1)->days();
        $this->artisan('digest:recipients')->expectsOutputToContain('Queued 0 weekly counts')->assertSuccessful();
        Mail::assertQueued(RecipientSummary::class, 1);
        $this->assertSame('2026-11-16', $recipient->fresh()->summary_week->toDateString());

        $this->message($form, '2026-11-25 10:00');
        $this->travelTo(Carbon::parse('2026-11-30 07:30', 'Europe/Amsterdam'));
        $this->artisan('digest:recipients')->assertSuccessful();
        Mail::assertQueued(RecipientSummary::class, 2);
        Mail::assertQueued(RecipientSummary::class, fn ($mail) => $mail->weekStart === '2026-11-23');
    }

    public function test_the_cap_leaves_the_rest_for_the_next_run_and_dry_run_sends_nothing(): void
    {
        Mail::fake();
        // This is about the cap per run; the daily summary budget (MailBudgetTest) is set out of its way.
        config(['mail.budget.summaries_per_day' => 1000]);

        $owner = User::factory()->create();
        $cap = SendRecipientSummaries::MAX_PER_RUN;
        $forms = (int) ceil(($cap + 3) / FormRecipient::MAX_PER_FORM);
        for ($i = 0; $i < $forms; $i++) {
            $form = Form::factory()->for($owner)->create(['recipients_can_view' => true]);
            for ($j = 0; $j < FormRecipient::MAX_PER_FORM; $j++) {
                $form->recipients()->create(['email' => "r{$i}-{$j}@example.org"])->forceFill(['confirmed_at' => now()->subMonth()])->save();
            }
            $this->message($form, '2026-11-18 10:00');
        }
        $total = $forms * FormRecipient::MAX_PER_FORM;

        $this->artisan('digest:recipients --dry-run')->expectsOutputToContain("Would send {$cap} weekly counts")->assertSuccessful();
        Mail::assertNothingQueued();
        $this->assertSame(0, FormRecipient::whereNotNull('summary_week')->count());

        $this->artisan('digest:recipients')->expectsOutputToContain(($total - $cap).' left for the next run')->assertSuccessful();
        Mail::assertQueued(RecipientSummary::class, $cap);

        $this->travel(1)->days();
        $this->artisan('digest:recipients')->expectsOutputToContain('Queued '.($total - $cap).' weekly counts')->assertSuccessful();
        Mail::assertQueued(RecipientSummary::class, $total);
        $this->assertSame($total, FormRecipient::where('summary_week', '>=', '2026-11-16')->count());
    }

    public function test_form_option_targets_one_form(): void
    {
        Mail::fake();

        [$one] = $this->formWithClient(email: 'one@example.org');
        [$two] = $this->formWithClient(email: 'two@example.org');
        $this->message($one, '2026-11-18 10:00');
        $this->message($two, '2026-11-18 10:00');

        $this->artisan('digest:recipients', ['--form' => $two->public_id])->assertSuccessful();

        Mail::assertQueued(RecipientSummary::class, 1);
        Mail::assertQueued(RecipientSummary::class, fn ($mail) => $mail->hasTo('two@example.org'));
    }

    public function test_the_email_is_short_under_the_owners_name_and_holds_nothing_a_visitor_typed(): void
    {
        [$form, $recipient] = $this->formWithClient();
        $this->message($form, '2026-11-18 10:00', data: ['name' => 'Anna Visitor', 'message' => 'Secret order, see https://visitor-link.example/x <img src="https://tracker.example/p.gif">']);
        $this->message($form, '2026-11-19 10:00');

        $this->artisan('digest:recipients')->assertSuccessful();

        $sent = collect(app('mailer')->getSymfonyTransport()->messages())->map->getOriginalMessage()
            ->first(fn ($m) => $m->getTo()[0]->getAddress() === 'client@bakery.example');
        $this->assertNotNull($sent, 'The summary went out through the real mailer');
        [$html, $text] = [$sent->getHtmlBody(), $sent->getTextBody()];

        $this->assertSame('Studio Noord', $sent->getFrom()[0]->getName());
        $this->assertSame('Bakery contact: 2 messages last week', $sent->getSubject());

        foreach ([$html, $text] as $body) {
            $this->assertStringContainsString('From 16 to 22 November, 2 messages came in through Bakery contact.', $body);
            $this->assertStringContainsString('Look in your spam or junk folder, and mark '.config('mail.from.address').' as a safe sender', $body);
            $this->assertStringContainsString('bakery.example', $body);
            $this->assertStringNotContainsString('Anna Visitor', $body);
            $this->assertStringNotContainsString('Secret order', $body);
            $this->assertStringNotContainsString('visitor-link.example', $body);
            $this->assertStringNotContainsString('tracker.example', $body);
            $this->assertStringNotContainsString('FormHaven', $body);
        }
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString(e($recipient->messagesUrl()), $html);
        $this->assertStringContainsString($recipient->messagesUrl(), $text);
        $this->assertStringContainsString($recipient->summaryStopUrl(), $text);
        $this->assertSame(2, substr_count($html, '<a '), 'Two links only: the messages page and the stop link');

        $headers = $sent->getHeaders();
        $this->assertSame('<'.$recipient->summaryStopUrl().'>', $headers->get('List-Unsubscribe')->getBodyAsString());
        $this->assertSame('List-Unsubscribe=One-Click', $headers->get('List-Unsubscribe-Post')->getBodyAsString());
    }

    public function test_one_message_and_a_form_that_keeps_fewer_days_than_the_week_read_right(): void
    {
        Mail::fake();

        [$form] = $this->formWithClient(['retention_days' => 3]);
        $this->message($form, '2026-11-21 10:00');

        $this->artisan('digest:recipients')->assertSuccessful();

        Mail::assertQueued(RecipientSummary::class, function (RecipientSummary $mail) {
            $mail->assertHasSubject('Bakery contact: at least 1 message last week');
            $mail->assertSeeInText('From 16 to 22 November, at least 1 message came in');
            $mail->assertSeeInText('Did you not see it in your inbox?');

            return $mail->partial;
        });
    }

    public function test_the_stop_link_stops_only_the_count_and_the_message_emails_keep_coming(): void
    {
        [$form, $recipient] = $this->formWithClient();
        $url = $recipient->summaryStopUrl();

        $this->get($url)->assertOk()->assertSee('Stop the weekly count?')->assertSee('Emails about each message keep coming.');
        $this->assertNull($recipient->fresh()->summary_stopped_at, 'Opening the link (a mail scanner does) changes nothing');

        // What a mail app's one-click unsubscribe sends: a bare POST.
        $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('Weekly count stopped');
        $this->assertNotNull($recipient->fresh()->summary_stopped_at);
        $this->assertTrue($form->recipients()->whereKey($recipient->id)->exists(), 'The address stays on the form');

        $this->post(str_replace('signature=', 'signature=x', $url))->assertForbidden();

        Mail::fake();
        $this->post("/f/{$form->public_id}", ['message' => 'A new lead'])->assertRedirect();
        Mail::assertQueued(SubmissionReceived::class, fn ($mail) => $mail->hasTo('client@bakery.example'));

        $this->message($form, '2026-11-18 10:00');
        $this->artisan('digest:recipients')->assertSuccessful();
        Mail::assertNotQueued(RecipientSummary::class);

        $this->assertFalse($recipient->fresh()->toApi()['weekly_summary']);
        $this->assertTrue($recipient->fresh()->toApi()['weekly_summary_stopped']);
    }

    public function test_a_summary_queued_before_a_stop_or_a_switch_off_is_not_sent(): void
    {
        [$form, $recipient] = $this->formWithClient();
        $mail = new RecipientSummary($recipient, 2, '2026-11-16');

        $recipient->forceFill(['summary_stopped_at' => now()])->save();
        Mail::to($recipient->email)->send($mail);

        $form->update(['recipients_weekly_summary' => false]);
        $recipient->forceFill(['summary_stopped_at' => null])->save();
        Mail::to($recipient->email)->send(new RecipientSummary($recipient, 2, '2026-11-16'));

        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_a_refused_summary_breaks_nothing_and_marks_nothing_undelivered(): void
    {
        config([
            'mail.default' => 'cloudflare',
            'mail.from.address' => 'hello@forms.example.test',
            'services.cloudflare_email.account_id' => 'acc123',
            'services.cloudflare_email.token' => 'tok',
        ]);
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['permanent_bounces' => ['client@bakery.example']]])]);
        Log::spy();

        [$form, $recipient] = $this->formWithClient();
        [$second] = $this->formWithClient(email: 'second@example.org');
        $this->message($form, '2026-11-18 10:00');
        $this->message($second, '2026-11-18 10:00');

        $this->artisan('digest:recipients')->expectsOutputToContain('Queued 2 weekly counts')->assertSuccessful();

        Http::assertSentCount(2);
        $this->assertSame(0, DeliveryFailure::count(), 'No failure row: one without a submission reads as a lost confirmation link');
        $this->assertSame('ok', $form->fresh()->health()['status']);
        Log::shouldHaveReceived('warning')->with('Weekly count to a recipient not delivered', \Mockery::on(fn ($context) => ! str_contains(json_encode($context), 'client@bakery.example')));
        $this->assertNotNull($recipient->fresh()->summary_week, 'Not sent again the next morning');
    }

    public function test_the_setting_is_on_by_default_and_rest_mcp_and_the_dashboard_switch_and_report_it(): void
    {
        [$form, $recipient] = $this->formWithClient();
        $this->assertTrue($form->fresh()->recipients_weekly_summary);
        $headers = $this->key($form->user);

        $this->getJson("/api/v1/forms/{$form->public_id}", $headers)->assertOk()
            ->assertJsonPath('data.recipients_weekly_summary', true)
            ->assertJsonPath('data.recipients.0.weekly_summary', true)
            ->assertJsonPath('data.recipients.0.weekly_summary_stopped', false);

        $this->patchJson("/api/v1/forms/{$form->public_id}", ['recipients_weekly_summary' => false], $headers)->assertOk()
            ->assertJsonPath('data.recipients_weekly_summary', false)
            ->assertJsonPath('data.recipients.0.weekly_summary', false);
        $this->assertFalse($form->fresh()->recipients_weekly_summary);

        $this->patchJson("/api/v1/forms/{$form->public_id}", ['recipients_weekly_summary' => 'often'], $headers)->assertUnprocessable();

        FormHavenServer::actingAs($form->user)
            ->tool(UpdateFormEmailsTool::class, ['form_id' => $form->public_id, 'recipients_weekly_summary' => true])
            ->assertOk()->assertSee('"recipients_weekly_summary":true');
        $this->assertTrue($form->fresh()->recipients_weekly_summary);

        // With the page off, the address does not get it, whatever this setting says.
        $form->update(['recipients_can_view' => false]);
        $this->assertFalse($recipient->fresh()->toApi()['weekly_summary']);

        $this->actingAs($form->user);
        Volt::test('forms.show', ['form' => $form->fresh()])
            ->assertSet('recipients_weekly_summary', true)
            ->set('recipients_weekly_summary', false)
            ->call('saveEmails');
        $this->assertFalse($form->fresh()->recipients_weekly_summary);
    }
}
