<?php

namespace Tests\Feature;

use App\Actions\ReceiveSubmission;
use App\Mail\DeliveryRefused;
use App\Mail\WeeklyDigest;
use App\Models\DeliveryFailure;
use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * The quiet badge and the Monday summary. The quiet rule's axis is how often a form usually gets
 * mail, so the cases cover a busy form, a slow one, a new one and a paused one.
 */
class WeeklyDigestTest extends TestCase
{
    use RefreshDatabase;

    /** A form that got $count messages, $gapHours apart, the last one $hoursAgo ago. */
    private function formWithRhythm(int $count, int $gapHours, int $hoursAgo, array $attributes = []): Form
    {
        return Form::factory()->create($attributes + [
            'messages_total' => $count,
            'typical_gap_hours' => $gapHours,
            'last_message_at' => now()->subHours($hoursAgo),
        ]);
    }

    public function test_a_busy_form_is_quiet_after_a_week_and_not_before(): void
    {
        $this->assertNull($this->formWithRhythm(20, 12, 6 * 24)->quietDays());

        $form = $this->formWithRhythm(20, 12, 7 * 24 + 2);
        $this->assertSame(7, $form->quietDays());
        $this->assertSame('quiet', $form->health()['status']);
        $this->assertSame('No messages for 7 days. This form usually gets one every 12 hours.', $form->health()['problems'][0]['message']);
        $this->assertSame('quiet', $form->dashboardStatus(null));
    }

    public function test_a_slow_form_gets_four_times_its_gap(): void
    {
        $this->assertNull($this->formWithRhythm(8, 240, 20 * 24)->quietDays(), 'Twenty days is normal for a form that gets mail every ten');
        $this->assertSame(41, $this->formWithRhythm(8, 240, 41 * 24)->quietDays());
    }

    public function test_a_new_form_and_a_paused_form_are_never_quiet(): void
    {
        $this->assertNull($this->formWithRhythm(Form::QUIET_MIN_MESSAGES - 1, 12, 60 * 24)->quietDays());

        $paused = $this->formWithRhythm(20, 12, 30 * 24, ['user_id' => User::factory()->create(['trial_ends_at' => now()->subDay()])->id]);
        $this->assertNull($paused->user->plan(), 'Precondition: the trial ended, so the form is paused');
        $this->assertNull($paused->quietDays());
    }

    public function test_a_real_message_clears_it_and_a_dashboard_test_does_not(): void
    {
        $form = $this->formWithRhythm(20, 12, 10 * 24);
        $receive = app(ReceiveSubmission::class);

        $receive->handle($form, ['message' => 'Test'], ['dashboard_test' => true]);
        $this->assertNotNull($form->fresh()->quietDays(), 'A test from the dashboard is not a visitor writing in');

        $receive->handle($form->fresh(), ['message' => 'A real question']);
        $form = $form->fresh();
        $this->assertNull($form->quietDays());
        $this->assertSame(21, $form->messages_total);
        $this->assertSame((int) round(0.8 * 12 + 0.2 * 240), $form->typical_gap_hours);
    }

    public function test_a_quiet_form_with_a_failed_delivery_needs_attention_first(): void
    {
        $form = $this->formWithRhythm(20, 12, 10 * 24);
        DeliveryFailure::record($form, $form->submissions()->create(['data' => ['m' => 'x']]), null, $form->user->email, new DeliveryRefused('x', DeliveryRefused::BOUNCED));

        $health = $form->fresh()->health();
        $this->assertSame('attention', $health['status']);
        $this->assertSame(['UNDELIVERED', 'QUIET'], array_column($health['problems'], 'code'));
    }

    public function test_the_overview_filters_on_quiet(): void
    {
        $quiet = $this->formWithRhythm(20, 12, 10 * 24, ['name' => 'Old contact form']);
        Form::factory()->for($quiet->user)->create(['name' => 'Busy form', 'messages_total' => 20, 'typical_gap_hours' => 12, 'last_message_at' => now()]);
        $this->actingAs($quiet->user);

        Volt::test('forms.index')->set('status', 'quiet')->assertSee('Old contact form')->assertDontSee('Busy form');
    }

    public function test_the_summary_goes_to_the_right_owners_only(): void
    {
        Mail::fake();
        $make = fn (array $user = [], int $total = 3) => Form::factory()->for(User::factory()->create($user))->create(['messages_total' => $total])->user;

        $gets = $make();
        $make(['weekly_digest' => false]);
        $make(['email_verified_at' => null]);
        $make(['trial_ends_at' => now()->subDay()]);
        $make([], 0);
        $make(['digest_sent_at' => now()->subDays(3)]);

        $this->artisan('digest:send')->expectsOutputToContain('Queued 1 weekly summaries.')->assertSuccessful();

        Mail::assertQueued(WeeklyDigest::class, 1);
        Mail::assertQueued(WeeklyDigest::class, fn ($mail) => $mail->hasTo($gets->email));
        $this->assertNotNull($gets->fresh()->digest_sent_at);

        $this->artisan('digest:send')->expectsOutputToContain('Queued 0 weekly summaries.');
        Mail::assertQueued(WeeklyDigest::class, 1);
    }

    public function test_a_dry_run_sends_nothing_and_the_cap_leaves_the_rest_for_next_time(): void
    {
        Mail::fake();
        $this->artisan('digest:send --dry-run')->expectsOutputToContain('Would send 0');

        Form::factory()->count(3)->create(['messages_total' => 2]);
        $this->artisan('digest:send --dry-run')->expectsOutputToContain('Would send 3 weekly summaries.');
        Mail::assertNothingQueued();

        $this->assertSame(100, \App\Console\Commands\SendWeeklyDigest::MAX_PER_RUN);
    }

    public function test_the_counts_match_what_came_in_per_site_and_form(): void
    {
        $user = User::factory()->create();
        $contact = Form::factory()->for($user)->create(['name' => 'Contact', 'site_url' => 'https://bakker.nl', 'messages_total' => 9]);
        $quote = Form::factory()->for($user)->create(['name' => 'Quote', 'site_url' => 'https://bakker.nl', 'retention_days' => 7]);
        $other = Form::factory()->for($user)->create(['name' => 'Booking', 'site_url' => 'https://salon.nl']);
        $at = fn (Form $form, int $days, bool $spam = false) => $form->submissions()->create(['data' => ['m' => 'x'], 'spam' => $spam])->forceFill(['created_at' => now()->subDays($days)])->save();

        $at($contact, 1); $at($contact, 2); $at($contact, 9); $at($contact, 1, true);
        $at($quote, 3);
        $at($other, 10); $at($other, 12);
        $at($contact, 20);

        $figures = (new WeeklyDigest($user))->figures();

        $this->assertSame(3, $figures['week']);
        $this->assertNull($figures['before'], 'The quote form keeps 7 days, so a total for the week before would read too low');
        $this->assertSame(1, $figures['spam']);
        $forms = $figures['sites']->flatten(1)->keyBy('name');
        $this->assertSame([2, 1, 1], [$forms['Contact']->digest_week, $forms['Contact']->digest_before, $forms['Contact']->digest_spam]);
        $this->assertNull($forms['Quote']->digest_before);
        $this->assertSame([0, 2], [$forms['Booking']->digest_week, $forms['Booking']->digest_before]);
        $this->assertSame(['bakker.nl', 'salon.nl'], $figures['sites']->keys()->all());

        $html = (new WeeklyDigest($user))->render();
        $this->assertStringContainsString('3 messages came in across your forms', $html);
        $this->assertStringContainsString('Contact: 2 messages (1 the week before), 1 spam caught', html_entity_decode($html));
        $this->assertStringContainsString('so no comparison', $html);
        (new WeeklyDigest($user))->assertHasSubject('Your forms last week: 3 messages');
    }

    public function test_problems_and_quiet_forms_are_listed_first_and_named_in_the_subject(): void
    {
        $form = $this->formWithRhythm(20, 12, 10 * 24, ['name' => 'Old contact form']);

        $mail = new WeeklyDigest($form->user);

        $mail->assertHasSubject('Your forms last week: 0 messages, 1 form needs a look');
        $html = html_entity_decode($mail->render());
        $this->assertLessThan(strpos($html, 'Per site'), strpos($html, 'Needs a look'));
        $this->assertStringContainsString('Old contact form', $html);
        $this->assertStringContainsString('No messages for 10 days.', $html);
    }

    public function test_the_stop_link_turns_it_off_and_a_forged_one_does_not(): void
    {
        $user = User::factory()->create();
        $url = $user->digestStopUrl();

        $this->get($url)->assertOk()->assertSee('Turn it off');
        $this->assertTrue($user->fresh()->weekly_digest, 'Opening the link (a mail scanner does) changes nothing');

        $this->post(str_replace('signature=', 'signature=x', $url))->assertForbidden();
        $this->assertTrue($user->fresh()->weekly_digest);

        $this->post($url)->assertOk()->assertSee('Weekly summary turned off');
        $this->assertFalse($user->fresh()->weekly_digest);
    }

    public function test_the_settings_switch_saves_at_once(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('settings.profile')->assertSet('weekly_digest', true)->set('weekly_digest', false);

        $this->assertFalse($user->fresh()->weekly_digest);
    }

    public function test_the_summary_carries_one_click_unsubscribe(): void
    {
        $user = User::factory()->create();

        $headers = (new WeeklyDigest($user))->headers()->text;

        $this->assertSame('<'.$user->digestStopUrl().'>', $headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
    }

    public function test_the_migration_backfill_reads_the_rhythm_from_stored_messages(): void
    {
        $form = Form::factory()->create();
        foreach ([30, 20, 10] as $days) {
            $form->submissions()->create(['data' => ['m' => 'x']])->forceFill(['created_at' => now()->subDays($days)])->save();
        }
        $form->submissions()->create(['data' => ['m' => 'x'], 'spam' => true]);

        $migration = require database_path('migrations/2026_11_15_090000_add_message_rhythm_and_weekly_digest.php');
        $migration->down();
        $migration->up();

        $form = $form->fresh();
        $this->assertSame(3, $form->messages_total);
        $this->assertSame(240, $form->typical_gap_hours);
        $this->assertTrue($form->last_message_at->isSameDay(now()->subDays(10)));
    }
}
