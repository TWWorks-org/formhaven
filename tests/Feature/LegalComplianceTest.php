<?php

namespace Tests\Feature;

use App\Mail\AbuseReportFiled;
use App\Mail\AccountDeletionLink;
use App\Mail\EmailAddressChanged;
use App\Mail\AbuseReportReceived;
use App\Models\AbuseReport;
use App\Models\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use Livewire\Volt\Volt;
use RuntimeException;
use Tests\TestCase;

/**
 * What the terms, privacy policy and DPA promise, checked against what the code does.
 * Each test names the promise it holds the code to.
 */
class LegalComplianceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Cashier::useSubscriptionModel(Subscription::class);
        RecordingSubscription::$cancelled = [];
        RecordingSubscription::$fail = false;
        parent::tearDown();
    }

    private function subscribe(User $user, string $status = 'active'): void
    {
        Cashier::useSubscriptionModel(RecordingSubscription::class);
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_'.$user->id.'_'.$status,
            'stripe_status' => $status,
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ])->items()->create(['stripe_id' => 'si_'.$user->id.'_'.$status, 'stripe_product' => 'prod_test', 'stripe_price' => 'price_test', 'quantity' => 1]);
    }

    // Terms are offered before the contract is made (art. 6:234 BW).

    public function test_an_account_cannot_be_made_without_accepting_the_terms(): void
    {
        Volt::test('auth.register')
            ->set('name', 'Anna')->set('email', 'anna@example.net')
            ->set('password', 'password')->set('password_confirmation', 'password')
            ->call('register')
            ->assertHasErrors(['terms' => 'accepted']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_accepting_the_terms_is_recorded_with_the_time(): void
    {
        $this->travelTo(now()->startOfMinute());

        Volt::test('auth.register')
            ->set('name', 'Anna')->set('email', 'anna@example.net')
            ->set('password', 'password')->set('password_confirmation', 'password')
            ->set('terms', true)
            ->call('register')
            ->assertHasNoErrors();

        $this->assertEquals(now(), User::sole()->terms_accepted_at);
    }

    public function test_both_signup_paths_link_the_terms_dpa_and_privacy_policy(): void
    {
        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);

        foreach (['/register', '/login'] as $page) {
            $html = $this->get($page)->assertOk()->getContent();
            foreach (['terms', 'dpa', 'privacy'] as $doc) {
                $this->assertStringContainsString(route('legal', $doc), $html, "{$page} does not link /legal/{$doc}");
            }
        }
    }

    // "You can delete your account yourself in Settings", and a deleted account is not charged.

    public function test_deleting_an_account_cancels_its_subscription_and_removes_everything(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);
        $this->subscribe($user, 'canceled');
        $user->createToken('agent');
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        Form::factory()->for($user)->create();

        $this->actingAs($user);
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')
            ->assertHasNoErrors()->assertRedirect('/');

        $this->assertSame(['sub_'.$user->id.'_active'], RecordingSubscription::$cancelled, 'only the live subscription is cancelled at Stripe');
        $this->assertNull($user->fresh());
        foreach (['subscriptions', 'subscription_items', 'personal_access_tokens', 'sessions', 'forms'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_if_stripe_fails_nothing_is_deleted_and_the_owner_is_told(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user);
        RecordingSubscription::$fail = true;

        $this->actingAs($user);
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')
            ->assertHasErrors(['password'])
            ->assertSee('nothing was deleted');

        $this->assertNotNull($user->fresh());
        $this->assertTrue(auth()->check());
    }

    // An account made through Google has no password anyone knows. Deleting it takes an emailed
    // link opened in a session of that same account: a stolen session alone cannot delete it.

    private function googleOnlyUser(array $attributes = []): User
    {
        return User::factory()->create(['email' => 'anna@example.net', 'google_id' => 'g-1', 'has_password' => false] + $attributes);
    }

    private function deletionLink(User $user, int $minutes = 30): string
    {
        return URL::temporarySignedRoute('account.delete', now()->addMinutes($minutes), ['user' => $user->id]);
    }

    public function test_a_google_only_account_cannot_be_deleted_by_typing_what_is_on_screen(): void
    {
        $user = $this->googleOnlyUser();
        $this->actingAs($user);

        Volt::test('settings.delete-user-form')->set('password', 'anna@example.net')->call('deleteUser')
            ->assertForbidden();

        $this->assertNotNull($user->fresh());
    }

    public function test_a_google_only_account_is_sent_a_deletion_link_and_nothing_is_deleted_yet(): void
    {
        Mail::fake();
        $user = $this->googleOnlyUser();
        $this->actingAs($user);

        Volt::test('settings.delete-user-form')->call('sendDeletionLink')
            ->assertHasNoErrors()->assertSet('linkSent', true)->assertSee('We sent a link to anna@example.net');

        Mail::assertSent(AccountDeletionLink::class, fn ($mail) => $mail->hasTo('anna@example.net'));
        $this->assertNotNull($user->fresh());
    }

    public function test_the_deletion_link_is_rate_limited(): void
    {
        Mail::fake();
        $this->actingAs($this->googleOnlyUser());

        foreach (range(1, 3) as $i) {
            Volt::test('settings.delete-user-form')->call('sendDeletionLink')->assertHasNoErrors();
        }
        Volt::test('settings.delete-user-form')->call('sendDeletionLink')->assertHasErrors(['link']);

        Mail::assertSentCount(3);
    }

    public function test_opening_the_link_only_asks_and_pressing_the_button_deletes(): void
    {
        $user = $this->googleOnlyUser();
        $this->subscribe($user);
        $link = $this->deletionLink($user);

        // A mail scanner opening the link must not delete anything.
        $this->actingAs($user)->get($link)->assertOk()->assertSee('Delete my account');
        $this->assertNotNull($user->fresh());

        $this->actingAs($user)->post($link)->assertOk()->assertSee('Your account is deleted');
        $this->assertNull($user->fresh());
        $this->assertSame(['sub_'.$user->id.'_active'], RecordingSubscription::$cancelled);
        $this->assertGuest();
    }

    public function test_the_link_needs_a_session_of_the_same_account(): void
    {
        $user = $this->googleOnlyUser();
        $someoneElse = User::factory()->create();
        $link = $this->deletionLink($user);

        $this->post($link)->assertRedirect(route('login'));
        $this->actingAs($someoneElse)->post($link)->assertForbidden()->assertSee('different account');

        $this->assertNotNull($user->fresh());
        $this->assertNotNull($someoneElse->fresh());
    }

    public function test_an_expired_or_changed_link_deletes_nothing(): void
    {
        $user = $this->googleOnlyUser();
        $other = User::factory()->create(['has_password' => false]);

        $expired = $this->deletionLink($user, 30);
        $this->travel(31)->minutes();
        $this->actingAs($user)->post($expired)->assertForbidden()->assertSee('expired');

        // The signature covers the user id: pointing a valid link at another account fails.
        $changed = str_replace('/account/delete/'.$user->id.'?', '/account/delete/'.$other->id.'?', $this->deletionLink($user));
        $this->actingAs($other)->post($changed)->assertForbidden()->assertSee('not complete');

        $this->assertNotNull($user->fresh());
        $this->assertNotNull($other->fresh());
    }

    public function test_a_google_linked_account_with_its_own_password_confirms_with_that_password(): void
    {
        $user = User::factory()->create(['google_id' => 'g-2', 'has_password' => true]);
        $this->actingAs($user);

        Volt::test('settings.delete-user-form')->set('password', $user->email)->call('deleteUser')
            ->assertHasErrors(['password']);
        $this->assertNotNull($user->fresh());

        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')
            ->assertHasNoErrors();
        $this->assertNull($user->fresh());
    }

    public function test_choosing_a_password_by_reset_turns_password_confirmation_back_on(): void
    {
        $user = $this->googleOnlyUser();
        $token = Password::createToken($user);

        Volt::test('auth.reset-password', ['token' => $token])
            ->set('email', $user->email)->set('password', 'new-password-123')->set('password_confirmation', 'new-password-123')
            ->call('resetPassword')->assertHasNoErrors();

        $this->assertTrue($user->fresh()->has_password);
    }

    // The account's email address receives deletion links and password resets, so changing it
    // must take more than a session: otherwise a stolen session hands over the whole account.

    public function test_changing_the_email_address_needs_the_current_password(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'anna@example.net']);
        $this->actingAs($user);

        Volt::test('settings.profile')->set('email', 'intruder@example.com')->call('updateProfileInformation')
            ->assertHasErrors(['current_password']);
        Volt::test('settings.profile')->set('email', 'intruder@example.com')->set('current_password', 'wrong')->call('updateProfileInformation')
            ->assertHasErrors(['current_password']);
        $this->assertSame('anna@example.net', $user->fresh()->email);
        Mail::assertNothingQueued();

        Volt::test('settings.profile')->set('email', 'anna@new.example')->set('current_password', 'password')->call('updateProfileInformation')
            ->assertHasNoErrors();
        $this->assertSame('anna@new.example', $user->fresh()->email);
        Mail::assertQueued(EmailAddressChanged::class, fn ($mail) => $mail->hasTo('anna@example.net'));
    }

    public function test_a_google_only_account_cannot_change_its_email_address_at_all(): void
    {
        Mail::fake();
        $user = $this->googleOnlyUser();
        $this->actingAs($user);

        Volt::test('settings.profile')->set('email', 'intruder@example.com')->call('updateProfileInformation')
            ->assertHasErrors(['email']);

        $this->assertSame('anna@example.net', $user->fresh()->email);
        $this->assertNotNull($user->fresh()->email_verified_at);
        Mail::assertNothingQueued();
    }

    public function test_renaming_still_works_without_a_password(): void
    {
        $user = $this->googleOnlyUser();
        $this->actingAs($user);

        Volt::test('settings.profile')->set('name', 'Anna B')->call('updateProfileInformation')->assertHasNoErrors();

        $this->assertSame('Anna B', $user->fresh()->name);
    }

    // DPA section 4: a form that keeps no submissions keeps nothing personal once emailed.

    public function test_a_forgotten_submission_keeps_no_personal_data(): void
    {
        $submission = Form::factory()->create()->submissions()->create([
            'data' => ['email' => 'visitor@example.net'], 'ip_hash' => str_repeat('a', 64),
            'user_agent' => 'Firefox', 'referer' => 'https://site.nl/contact',
        ]);

        $submission->forget();

        $row = (array) DB::table('submissions')->find($submission->id);
        foreach (['ip_hash', 'user_agent', 'referer'] as $column) {
            $this->assertNull($row[$column], "{$column} survives forget()");
        }
        $this->assertSame('[]', $row['data']);
        $this->assertSame('', (string) $row['search_text']);
    }

    // DSA art. 16: anyone can report a form; the reporter gets a receipt.

    public function test_a_report_is_stored_acknowledged_and_passed_on(): void
    {
        Mail::fake();

        $this->get('/report')->assertOk()->assertSee('Report a form');
        $this->post('/report', [
            'url' => 'https://phish.example/login',
            'reason' => 'This form copies a bank login page and asks for passwords.',
            'name' => 'Piet', 'email' => 'piet@example.net', 'good_faith' => '1',
        ])->assertRedirect('/report');

        $report = AbuseReport::sole();
        $this->assertSame('https://phish.example/login', $report->url);
        Mail::assertQueued(AbuseReportReceived::class, fn ($mail) => $mail->hasTo('piet@example.net'));
        Mail::assertQueued(AbuseReportFiled::class, fn ($mail) => $mail->hasTo(config('legal.email')) && $mail->hasReplyTo('piet@example.net'));
        $this->get('/report')->assertSee('Your report is in');
    }

    public function test_the_receipt_repeats_nothing_the_reporter_typed(): void
    {
        // The receipt goes to any address someone types in, so it must not carry their text.
        $report = AbuseReport::create(['url' => 'https://spam.example/buy-now', 'reason' => 'Cheap pills at https://spam.example', 'name' => 'Visit spam.example', 'email' => 'victim@example.net']);

        $html = (new AbuseReportReceived($report))->render();

        foreach (['spam.example', 'Cheap pills', 'Visit'] as $typed) {
            $this->assertStringNotContainsString($typed, $html);
        }
        $this->assertStringContainsString('#'.$report->id, $html);
    }

    public function test_a_report_needs_the_four_things_a_valid_notice_has(): void
    {
        Mail::fake();

        $this->post('/report', ['url' => '', 'reason' => 'bad', 'name' => '', 'email' => 'not-an-email'])
            ->assertSessionHasErrors(['url', 'reason', 'name', 'email', 'good_faith']);

        $this->assertDatabaseCount('abuse_reports', 0);
        Mail::assertNothingQueued();
    }

    public function test_a_bot_filling_the_hidden_field_sends_nothing(): void
    {
        Mail::fake();

        $this->post('/report', ['url' => 'x', 'reason' => str_repeat('spam ', 10), 'name' => 'Bot', 'email' => 'bot@example.net', 'good_faith' => '1', '_gotcha' => 'filled'])
            ->assertRedirect('/report');

        $this->assertDatabaseCount('abuse_reports', 0);
        Mail::assertNothingQueued();
    }

    public function test_reports_are_deleted_after_two_years_as_the_privacy_policy_says(): void
    {
        $old = AbuseReport::create(['url' => 'a', 'reason' => 'old', 'name' => 'A', 'email' => 'a@example.net']);
        $old->forceFill(['created_at' => now()->subYears(2)->subDay()])->save();
        $recent = AbuseReport::create(['url' => 'b', 'reason' => 'recent', 'name' => 'B', 'email' => 'b@example.net']);
        $recent->forceFill(['created_at' => now()->subYears(2)->addDay()])->save();

        $this->artisan('model:prune', ['--model' => [AbuseReport::class]])->assertSuccessful();

        $this->assertSame([$recent->id], AbuseReport::pluck('id')->all());
    }

    public function test_failed_jobs_holding_form_answers_are_deleted_after_a_week(): void
    {
        $insert = fn (string $uuid, $failedAt) => DB::table('failed_jobs')->insert([
            'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default',
            'payload' => '{"answers":"visitor@example.net"}', 'exception' => 'x', 'failed_at' => $failedAt,
        ]);
        $insert('old', now()->subDays(8));
        $insert('recent', now()->subDays(6));

        $this->artisan('queue:prune-failed', ['--hours' => 168])->assertSuccessful();

        $this->assertSame(['recent'], DB::table('failed_jobs')->pluck('uuid')->all());
        $this->assertTrue(collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->contains(fn ($event) => str_contains($event->command, 'queue:prune-failed') && str_contains($event->command, '168')),
            'the daily prune of failed jobs is not scheduled');
    }

    // The documents themselves.

    public function test_every_legal_page_renders_with_its_placeholders_filled_and_links_the_report_page(): void
    {
        foreach (['privacy', 'terms', 'dpa', 'subprocessors'] as $page) {
            $html = $this->get("/legal/{$page}")->assertOk()->getContent();
            preg_match('#<main\b[^>]*>(.*)</main>#s', $html, $m);

            $this->assertDoesNotMatchRegularExpression('/:(entity|address|kvk|email|updated|trial_days|email_grace)\b/', $m[1], "{$page} shows a raw placeholder");
            $this->assertStringNotContainsString('—', $m[1], "{$page} contains an em dash");
            $this->assertStringContainsString(route('report'), $html, "{$page} has no link to the report page");
        }
    }

    public function test_the_documents_name_no_backup_provider_the_server_does_not_use(): void
    {
        // Backblaze and R2 were listed while no backup ran (found 2026-10-29). Add a provider
        // here only together with the backup job that uses it.
        foreach (['dpa', 'subprocessors', 'privacy'] as $page) {
            $text = $this->get("/legal/{$page}")->getContent();
            $this->assertStringNotContainsString('Backblaze', $text, "{$page} names Backblaze");
            $this->assertStringNotContainsString('R2', $text, "{$page} names R2");
        }
    }

    public function test_the_documents_state_how_long_the_nightly_copies_are_kept(): void
    {
        // The number comes from config/legal.php. Where the hosted backup script is present (it is not
        // part of the public source), its KEEP must match, so the pages cannot drift from the job.
        $nights = (int) config('legal.backup_days');
        $script = base_path('deploy/backup-local.sh');
        if (is_file($script)) {
            preg_match('/^KEEP=(\d+)$/m', file_get_contents($script), $m);
            $this->assertSame($nights, (int) ($m[1] ?? 0), 'backup-local.sh KEEP differs from legal.backup_days');
        }

        $this->assertStringContainsString("kept for {$nights} nights", $this->get('/legal/dpa')->getContent());
        $this->assertStringContainsString("up to {$nights} days", $this->get('/legal/dpa')->getContent());
        $this->assertStringContainsString("up to {$nights} more days", $this->get('/legal/privacy')->getContent());
        $this->assertStringContainsString("backup copies within {$nights} days", $this->get('/legal/terms')->getContent());

        // The paragraph site owners paste into their own privacy policy, for a form that keeps messages and one that keeps none.
        $phrases = ['en' => "up to {$nights} days longer", 'nl' => "hooguit {$nights} dagen langer", 'de' => "höchstens {$nights} Tage länger"];
        foreach ([true, false] as $store) {
            $form = new \App\Models\Form(['retention_days' => 30, 'store_submissions' => $store]);
            foreach ($phrases as $lang => $phrase) {
                $text = \App\Support\PrivacyText::for($form, $lang);
                $this->assertStringContainsString($phrase, $text['paragraph'], "The {$lang} privacy text no longer states the backup copies");
                $this->assertSame((int) $nights, $text['facts']['backup_copies_kept_days']);
            }
        }
    }
}

/** Records which subscriptions were cancelled at Stripe instead of calling it. */
class RecordingSubscription extends Subscription
{
    protected $table = 'subscriptions';

    public static array $cancelled = [];

    public static bool $fail = false;

    public function items(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Cashier::$subscriptionItemModel, 'subscription_id');
    }

    public function cancelNow()
    {
        if (static::$fail) {
            throw new RuntimeException('Stripe is down');
        }

        static::$cancelled[] = $this->stripe_id;
        $this->forceFill(['stripe_status' => 'canceled', 'ends_at' => now()])->save();

        return $this;
    }
}
