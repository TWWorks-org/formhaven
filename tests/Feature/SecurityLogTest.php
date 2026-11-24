<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\LogRetention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SecurityLogTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/formhaven-security-log-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->dir);
        config(['logging.channels.security.path' => $this->dir.'/security.log']);
        Notification::fake();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function log(): string
    {
        return implode('', array_map('file_get_contents', glob($this->dir.'/security-*.log') ?: []));
    }

    public function test_account_events_are_logged_without_secrets(): void
    {
        $user = User::factory()->create(['email' => 'holder@example.net', 'password' => 'correct-horse-77']);

        Volt::test('auth.login')->set('email', 'holder@example.net')->set('password', 'wrong-guess-99')->call('login');
        Volt::test('auth.login')->set('email', 'nobody@example.net')->set('password', 'wrong-guess-99')->call('login');
        Volt::test('auth.login')->set('email', 'holder@example.net')->set('password', 'correct-horse-77')->call('login');
        $this->actingAs($user);

        $key = Volt::test('api-keys')->set('name', 'Deploy key')->call('createKey')->get('newKey');
        $keyId = $user->tokens()->sole()->id;
        Volt::test('api-keys')->call('revoke', $keyId);

        Volt::test('settings.password')->set('current_password', 'correct-horse-77')->set('password', 'new-pass-word-88')->set('password_confirmation', 'new-pass-word-88')->call('updatePassword');
        Volt::test('settings.profile')->set('email', 'moved@example.net')->set('current_password', 'new-pass-word-88')->call('updateProfileInformation');

        auth()->logout();
        Volt::test('auth.forgot-password')->set('email', 'moved@example.net')->call('sendPasswordResetLink');
        $user->fresh()->delete();

        $log = $this->log();
        foreach ([
            'login.failed {"user_id":'.$user->id, 'login.failed {"user_id":null', 'login.succeeded {"user_id":'.$user->id,
            'api_key.created {"user_id":'.$user->id.',"key_id":'.$keyId, 'api_key.deleted {"user_id":'.$user->id.',"key_id":'.$keyId,
            'password.changed', 'email.changed', 'password.reset_requested', 'password.reset_link_sent', 'account.deleted {"user_id":'.$user->id,
        ] as $line) {
            $this->assertStringContainsString($line, $log);
        }
        $this->assertStringContainsString('"ip":"127.0.0.1"', $log);

        // Nothing anyone could log in with, and no typed addresses.
        foreach (['wrong-guess-99', 'correct-horse-77', 'new-pass-word-88', $key, explode('|', $key)[1], 'holder@example.net', 'nobody@example.net', 'moved@example.net'] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
    }

    public function test_throttle_hits_are_logged(): void
    {
        User::factory()->create(['email' => 'holder@example.net']);

        for ($i = 0; $i < 6; $i++) {
            Volt::test('auth.login')->set('email', 'holder@example.net')->set('password', 'wrong')->call('login');
        }
        for ($i = 0; $i < 4; $i++) {
            Volt::test('auth.forgot-password')->set('email', 'holder@example.net')->call('sendPasswordResetLink');
            $this->travel(2)->minutes();
        }

        $log = $this->log();
        $this->assertStringContainsString('login.throttled', $log);
        $this->assertStringContainsString('account_mail.throttled {"kind":"password_reset","limit":"address"', $log);
    }

    public function test_a_spent_pool_is_logged(): void
    {
        config(['mail.budget.signup_per_day' => 1]);
        User::factory()->unverified()->create()->sendEmailVerificationNotification();
        $late = User::factory()->unverified()->create();
        $late->sendEmailVerificationNotification();

        $log = $this->log();
        $this->assertStringContainsString('account_mail.pool_spent {"pool":"signup","limit":1', $log);
        $this->assertStringContainsString('account_mail.held {"kind":"verify","pool":"signup","user_id":'.$late->id, $log);
    }

    public function test_a_log_that_cannot_be_written_does_not_break_a_login(): void
    {
        // A file where the log's folder should be: the channel cannot open its file.
        File::put($this->dir.'/blocker', 'x');
        config(['logging.channels.security.path' => $this->dir.'/blocker/security.log']);
        User::factory()->create(['email' => 'holder@example.net', 'password' => 'correct-horse-77']);

        Volt::test('auth.login')->set('email', 'holder@example.net')->set('password', 'correct-horse-77')->call('login')->assertHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_old_log_files_are_deleted_after_the_days_the_privacy_policy_gives(): void
    {
        // The policy and the config say the same number of days.
        $this->assertStringContainsString('Server logs: '.config('logging.retention_days').' days for application logs', File::get(resource_path('legal/privacy.md')));
        $this->assertSame(config('logging.retention_days'), config('logging.channels.security.days'));

        $this->travelTo(now()->setDate(2026, 11, 24));
        $files = [
            'laravel-2026-10-29.log' => false, 'security-2026-11-09.log' => false,
            'laravel-2026-11-10.log' => true, 'security-2026-11-24.log' => true,
            'queue.log' => true, 'backup-local.log' => true, 'security-notes.log' => true,
        ];
        foreach (array_keys($files) as $name) {
            File::put($this->dir.'/'.$name, 'x');
        }

        $this->assertSame(2, LogRetention::prune($this->dir));
        foreach ($files as $name => $kept) {
            $this->assertSame($kept, File::exists($this->dir.'/'.$name), $name);
        }
    }
}
