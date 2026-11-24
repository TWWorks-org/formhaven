<?php

namespace Tests\Feature;

use App\Mail\AccountDeletionLink;
use App\Mail\EmailAddressChanged;
use App\Models\Form;
use App\Models\User;
use App\Support\AccountMail;
use App\Support\MailBudget;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Every account email a visitor can cause is limited per visitor (IP), per address and per day,
 * and the daily account pool is separate from submission mail in both directions.
 */
class AccountMailLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Mail::fake();
    }

    /** For AccountMail::allow() called in this request. Livewire test calls always come from 127.0.0.1. */
    private function visitorAt(string $ip): void
    {
        request()->server->set('REMOTE_ADDR', $ip);
    }

    private function forgot(string $email)
    {
        return Volt::test('auth.forgot-password')->set('email', $email)->call('sendPasswordResetLink');
    }

    public function test_reset_links_to_one_address_stop_after_the_hourly_limit(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.net']);

        for ($i = 1; $i <= AccountMail::PER_ADDRESS; $i++) {
            $this->forgot('owner@example.net')->assertHasNoErrors();
            $this->travel(2)->minutes(); // past Laravel's own one-a-minute limit per account
        }

        $this->forgot('owner@example.net')->assertHasErrors('email')->assertSee('Too many account emails were asked for this address');
        // Case and spaces do not make a fresh address.
        $this->forgot('  OWNER@example.net ')->assertHasErrors('email');
        Notification::assertSentToTimes($user, ResetPassword::class, 3);

        // Another address from the same visitor still goes: the limit is per address.
        $other = User::factory()->create(['email' => 'second@example.net']);
        $this->forgot('second@example.net')->assertHasNoErrors();
        Notification::assertSentTo($other, ResetPassword::class);

        // The address lock lasts an hour, not a day: a stranger cannot shut the owner out for long.
        $this->travel(61)->minutes();
        $this->forgot('owner@example.net')->assertHasNoErrors();
        Notification::assertSentToTimes($user, ResetPassword::class, 4);
    }

    public function test_one_visitor_is_stopped_after_the_daily_limit(): void
    {
        for ($i = 1; $i <= AccountMail::PER_IP; $i++) {
            $this->forgot("nobody{$i}@example.net")->assertHasNoErrors();
        }
        $this->forgot('nobody-else@example.net')->assertHasErrors('email')->assertSee('from your connection today');
    }

    public function test_the_limits_follow_the_visitor_ip_and_the_address(): void
    {
        // One visitor: stopped after PER_IP, whatever the addresses.
        $this->visitorAt('203.0.113.7');
        for ($i = 1; $i <= AccountMail::PER_IP; $i++) {
            $this->assertNull(AccountMail::allow('password_reset', "a{$i}@example.net", MailBudget::ACCOUNT));
        }
        $this->assertStringContainsString('from your connection today', AccountMail::allow('password_reset', 'b@example.net', MailBudget::ACCOUNT));

        // A second and a third visitor have their own count (control: they are not stopped).
        foreach (['198.51.100.20', '2001:db8::1'] as $ip) {
            $this->visitorAt($ip);
            $this->assertNull(AccountMail::allow('password_reset', "c-{$ip}@example.net", MailBudget::ACCOUNT));
        }

        // One address: shared by every visitor, so spreading out over IPs does not help.
        foreach (['192.0.2.1', '192.0.2.2', '192.0.2.3'] as $ip) {
            $this->visitorAt($ip);
            $this->assertNull(AccountMail::allow('verify', 'target@example.net', MailBudget::SIGNUP));
        }
        $this->visitorAt('192.0.2.4');
        $this->assertStringContainsString('this address', AccountMail::allow('password_reset', 'target@example.net', MailBudget::ACCOUNT));
        $this->assertNull(AccountMail::allow('password_reset', 'someone-else@example.net', MailBudget::ACCOUNT));
    }

    public function test_an_address_without_an_account_costs_the_daily_pool_nothing(): void
    {
        $before = MailBudget::remaining(MailBudget::ACCOUNT);

        foreach ([1, 2, 3] as $i) {
            $this->forgot("ghost{$i}@example.net")->assertHasNoErrors();
        }

        $this->assertSame($before, MailBudget::remaining(MailBudget::ACCOUNT));
        Notification::assertNothingSent();
    }

    public function test_registration_is_limited_per_visitor(): void
    {
        for ($i = 1; $i <= AccountMail::PER_IP; $i++) {
            $this->register("new{$i}@example.net")->assertHasNoErrors();
            auth()->logout();
        }

        $this->register('one-more@example.net')->assertHasErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'one-more@example.net']);
        Notification::assertSentTimes(VerifyEmail::class, AccountMail::PER_IP);
    }

    private function register(string $email)
    {
        return Volt::test('auth.register')
            ->set('name', 'Test')
            ->set('email', $email)
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('terms', true)
            ->call('register');
    }

    public function test_resending_the_confirmation_is_limited_per_address_on_both_pages(): void
    {
        $user = User::factory()->unverified()->create(['email' => 'mine@example.net']);
        $this->actingAs($user);

        // Both pages: the address limit counts them together.
        Volt::test('auth.verify-email')->call('sendVerification')->assertHasNoErrors();
        Volt::test('settings.profile')->call('resendVerificationNotification')->assertHasNoErrors();
        Volt::test('auth.verify-email')->call('sendVerification')->assertHasNoErrors();

        Volt::test('auth.verify-email')->call('sendVerification')->assertHasErrors('email')->assertSee('Too many account emails');
        Volt::test('settings.profile')->call('resendVerificationNotification')->assertHasErrors('email');
        Notification::assertSentToTimes($user, VerifyEmail::class, 3);
    }

    public function test_the_email_change_notice_and_the_deletion_link_are_limited_too(): void
    {
        $user = User::factory()->create(['email' => 'first@example.net', 'password' => 'secret-pass']);
        $this->actingAs($user);

        // Each change mails the old address. Bouncing between two addresses mails both in turn.
        foreach (['second@example.net', 'first@example.net', 'second@example.net', 'first@example.net', 'second@example.net', 'first@example.net'] as $to) {
            Volt::test('settings.profile')->set('email', $to)->set('current_password', 'secret-pass')->call('updateProfileInformation')->assertHasNoErrors();
        }
        Mail::assertQueued(EmailAddressChanged::class, 6);

        // first@ has now had three: the next change away from it is refused and nothing changes.
        Volt::test('settings.profile')->set('email', 'third@example.net')->set('current_password', 'secret-pass')->call('updateProfileInformation')->assertHasErrors('email');
        $this->assertSame('first@example.net', $user->fresh()->email);
        Mail::assertQueued(EmailAddressChanged::class, 6);

        // The deletion link to an account made through Google comes out of the daily pool too.
        $google = User::factory()->create(['email' => 'g@example.net', 'has_password' => false]);
        $this->actingAs($google);
        $left = MailBudget::remaining(MailBudget::ACCOUNT);
        Volt::test('settings.delete-user-form')->call('sendDeletionLink')->assertHasNoErrors();
        $this->assertSame($left - 1, MailBudget::remaining(MailBudget::ACCOUNT));

        config(['mail.budget.account_per_day' => 0]);
        $this->travel(2)->hours();
        Volt::test('settings.delete-user-form')->call('sendDeletionLink')->assertHasErrors('link');
        Mail::assertSent(AccountDeletionLink::class, 1);
    }

    public function test_the_daily_ceiling_stops_account_mail_without_touching_submission_mail(): void
    {
        // Unverified accounts: anyone can make one, so they draw from the signup pool.
        config(['mail.budget.signup_per_day' => 2]);
        $a = User::factory()->unverified()->create();
        $b = User::factory()->unverified()->create();
        $c = User::factory()->unverified()->create();

        foreach ([$a, $b, $c] as $user) {
            $this->actingAs($user);
            $result = Volt::test('auth.verify-email')->call('sendVerification');
        }

        $result->assertHasErrors('email')->assertSee('all the account emails it can today');
        Notification::assertSentTimes(VerifyEmail::class, 2);
        Notification::assertNotSentTo($c, VerifyEmail::class);
        $this->register('late@example.net')->assertHasErrors('email');

        // Submission mail has its own pool and still goes.
        $form = Form::factory()->create();
        $this->assertNull(MailBudget::takeForForm($form));
        $this->assertSame(MailBudget::limit(MailBudget::NOTIFICATIONS) - 1, MailBudget::remaining(MailBudget::NOTIFICATIONS));

        // The next UTC day has room again.
        $this->travel(1)->days();
        $this->actingAs($c);
        Volt::test('auth.verify-email')->call('sendVerification')->assertHasNoErrors();
        Notification::assertSentTo($c, VerifyEmail::class);
    }

    public function test_spent_submission_mail_leaves_account_mail_alone(): void
    {
        config(['mail.budget.notifications_per_day' => 0]);
        $user = User::factory()->create();
        $this->assertNotNull(MailBudget::takeForForm(Form::factory()->create()));

        $this->forgot($user->email)->assertHasNoErrors();
        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_ipv6_visitors_are_counted_per_64(): void
    {
        // Ten addresses inside one /64 are one visitor: rotating inside the block buys nothing.
        for ($i = 1; $i <= AccountMail::PER_IP; $i++) {
            $this->visitorAt('2001:db8:aa:bb::'.dechex($i * 4099));
            $this->assertNull(AccountMail::allow('password_reset', "v6-{$i}@example.net", MailBudget::ACCOUNT));
        }
        foreach (['2001:db8:aa:bb:ffff:ffff:ffff:ffff', '2001:db8:aa:bb::1'] as $ip) {
            $this->visitorAt($ip);
            $this->assertStringContainsString('from your connection today', AccountMail::allow('password_reset', 'v6-more@example.net', MailBudget::ACCOUNT));
        }

        // The next /64 over is someone else (control), and so is IPv4.
        foreach (['2001:db8:aa:bc::1', '203.0.113.99'] as $ip) {
            $this->visitorAt($ip);
            $this->assertNull(AccountMail::allow('password_reset', "other-{$ip}@example.net", MailBudget::ACCOUNT));
        }

        $this->assertSame('2001:db8:aa:bb::/64', AccountMail::visitor('2001:db8:aa:bb:1:2:3:4'));
        $this->assertSame('203.0.113.99', AccountMail::visitor('203.0.113.99'));
    }

    public function test_ipv4_written_as_ipv6_counts_as_that_ipv4_address(): void
    {
        // Mapped and compatible forms are the plain IPv4 address, so each keeps its own count.
        $this->assertSame('203.0.113.7', AccountMail::visitor('::ffff:203.0.113.7'));
        $this->assertSame('203.0.113.8', AccountMail::visitor('::ffff:cb00:7108'));
        $this->assertSame('203.0.113.9', AccountMail::visitor('::203.0.113.9'));
        $this->assertNotSame(AccountMail::visitor('::ffff:203.0.113.7'), AccountMail::visitor('::ffff:198.51.100.7'));
        // :: and ::1 are not IPv4 addresses; ordinary IPv6 still groups per /64.
        $this->assertSame('::/64', AccountMail::visitor('::1'));
        $this->assertSame('::/64', AccountMail::visitor('::'));
        $this->assertSame(AccountMail::visitor('2001:db8:1:2::5'), AccountMail::visitor('2001:db8:1:2:ffff::9'));

        // As limits: a mapped visitor spending its count does not hold another mapped visitor,
        // and the same address in both spellings is one visitor.
        $this->visitorAt('::ffff:203.0.113.7');
        for ($i = 1; $i <= AccountMail::PER_IP; $i++) {
            $this->assertNull(AccountMail::allow('password_reset', "m{$i}@example.net", MailBudget::ACCOUNT));
        }
        $this->visitorAt('203.0.113.7');
        $this->assertNotNull(AccountMail::allow('password_reset', 'm-plain@example.net', MailBudget::ACCOUNT));
        $this->visitorAt('::ffff:198.51.100.7');
        $this->assertNull(AccountMail::allow('password_reset', 'm-other@example.net', MailBudget::ACCOUNT));
    }

    public function test_signups_spend_only_their_own_pool_and_real_accounts_still_get_mail(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.net']);
        $moved = User::factory()->create(['email' => 'moved@example.net']);
        // An account that proved its address, then changed it: unverified now, still a real account.
        $moved->forceFill(['email_verified_at' => null])->save();
        $this->assertNotNull($moved->fresh()->first_verified_at);

        // Signups until their pool is gone: from many visitors, so no per-visitor limit stops them.
        for ($i = 1; $i <= MailBudget::limit(MailBudget::SIGNUP) + 2; $i++) {
            $this->visitorAt("198.18.0.{$i}");
            $user = User::factory()->unverified()->create(['email' => "spam{$i}@example.net"]);
            $user->sendEmailVerificationNotification();
        }
        $this->assertSame(0, MailBudget::remaining(MailBudget::SIGNUP));
        Notification::assertSentTimes(VerifyEmail::class, MailBudget::limit(MailBudget::SIGNUP));
        $this->register('next-signup@example.net')->assertHasErrors('email');

        // A reset for an unverified (possibly made-up) account is in the signup pool and is held.
        $spam = User::where('email', 'spam1@example.net')->first();
        $this->forgot('spam1@example.net')->assertHasNoErrors();
        Notification::assertNotSentTo($spam, ResetPassword::class);

        // Real accounts are untouched: a reset and a re-confirmation both go.
        $this->forgot('owner@example.net')->assertHasNoErrors();
        Notification::assertSentTo($owner, ResetPassword::class);
        $this->actingAs($moved->fresh());
        Volt::test('auth.verify-email')->call('sendVerification')->assertHasNoErrors();
        Notification::assertSentTo($moved, VerifyEmail::class);
        $this->assertSame(MailBudget::limit(MailBudget::ACCOUNT) - 2, MailBudget::remaining(MailBudget::ACCOUNT));
    }

    public function test_the_pools_and_submission_mail_stay_under_the_shared_quota(): void
    {
        $total = array_sum(array_map(fn ($pool) => MailBudget::limit($pool), [MailBudget::NOTIFICATIONS, MailBudget::SUMMARIES, MailBudget::ABUSE, MailBudget::NOTICES, MailBudget::SIGNUP, MailBudget::ACCOUNT]));
        $this->assertLessThanOrEqual(160, $total, 'Cloudflare gives 200 a day, shared with other sites');
        $this->assertGreaterThan(MailBudget::limit(MailBudget::SIGNUP), MailBudget::limit(MailBudget::ACCOUNT));
    }
}
