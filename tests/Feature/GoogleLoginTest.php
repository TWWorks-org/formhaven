<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Tests\TestCase;

class GoogleLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'test-id', 'services.google.client_secret' => 'test-secret']);
    }

    private function googleReturns(string $id, string $email, bool $verified = true, string $name = 'Anna de Vries'): void
    {
        $user = (new GoogleUser)->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => $verified])
            ->map(['id' => $id, 'name' => $name, 'email' => $email]);

        Socialite::shouldReceive('driver->user')->andReturn($user);
    }

    public function test_the_button_shows_only_when_google_is_configured(): void
    {
        $this->get('/login')->assertSee('Continue with Google');
        $this->get('/register')->assertSee('Continue with Google');

        config(['services.google.client_id' => null]);
        $this->get('/login')->assertDontSee('Continue with Google');
        $this->get('/auth/google/redirect')->assertNotFound();
    }

    public function test_the_button_sends_the_visitor_to_google(): void
    {
        $this->get('/auth/google/redirect')->assertRedirectContains('accounts.google.com');
    }

    public function test_a_new_google_user_gets_a_verified_account_with_a_trial(): void
    {
        $this->googleReturns('g-123', 'Anna@Example.net');

        $this->get('/auth/google/callback')->assertRedirect(route('forms.index'));

        $user = User::where('email', 'anna@example.net')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('g-123', $user->google_id);
        $this->assertSame('Anna de Vries', $user->name);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame('trial', $user->planKey());
        $this->assertNotNull($user->terms_accepted_at, 'the button carries the terms notice, so a new account records the acceptance');
        $this->assertFalse($user->has_password, 'nobody knows the random password, so deletion must not rely on it');
    }

    public function test_an_existing_account_with_the_same_email_is_linked_not_duplicated(): void
    {
        $existing = User::factory()->create(['email' => 'anna@example.net']);
        $this->googleReturns('g-123', 'anna@example.net');

        $this->get('/auth/google/callback')->assertRedirect(route('forms.index'));

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::count());
        $this->assertSame('g-123', $existing->fresh()->google_id);
    }

    public function test_linking_an_unverified_account_locks_out_whoever_registered_it(): void
    {
        // Someone signs up with Anna's address and a password, never verifies, keeps an API key.
        $squatter = User::factory()->unverified()->create(['email' => 'anna@example.net', 'password' => 'squatter-pass', 'remember_token' => 'squat']);
        $squatter->createToken('squatter');
        \Illuminate\Support\Facades\DB::table('sessions')->insert(['id' => 'squat-session', 'user_id' => $squatter->id, 'payload' => '', 'last_activity' => time()]);

        $this->googleReturns('g-123', 'anna@example.net');
        $this->get('/auth/google/callback')->assertRedirect(route('forms.index'));

        $user = $squatter->fresh();
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertFalse(\Illuminate\Support\Facades\Hash::check('squatter-pass', $user->password));
        $this->assertNotSame('squat', $user->remember_token);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertFalse(\Illuminate\Support\Facades\DB::table('sessions')->where('id', 'squat-session')->exists());
    }

    public function test_linking_a_verified_account_keeps_its_password(): void
    {
        $owner = User::factory()->create(['email' => 'anna@example.net', 'password' => 'own-pass']);
        $this->googleReturns('g-123', 'anna@example.net');

        $this->get('/auth/google/callback');

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('own-pass', $owner->fresh()->password));
    }

    public function test_a_returning_google_user_is_found_by_google_id_after_an_email_change(): void
    {
        $existing = User::factory()->create(['email' => 'old@example.net', 'google_id' => 'g-123']);
        $this->googleReturns('g-123', 'new@example.net');

        $this->get('/auth/google/callback');

        $this->assertAuthenticatedAs($existing);
        $this->assertSame(1, User::count());
    }

    public function test_an_unverified_google_email_never_logs_into_an_existing_account(): void
    {
        User::factory()->create(['email' => 'anna@example.net']);
        $this->googleReturns('g-999', 'anna@example.net', verified: false);

        $this->get('/auth/google/callback')->assertRedirect(route('login'));
        $this->assertGuest();

        $this->get('/login')->assertSee('Your Google account has no verified email address.');
    }

    public function test_a_failed_google_answer_explains_itself_on_the_login_page(): void
    {
        Socialite::shouldReceive('driver->user')->andThrow(new \RuntimeException('state mismatch'));

        $this->get('/auth/google/callback')->assertRedirect(route('login'));
        $this->assertGuest();
        $this->get('/login')->assertSee('Signing in with Google did not work.');
    }
}
