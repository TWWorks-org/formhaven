<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/** "Continue with Google": one button that logs in an existing account or creates a new one. */
class GoogleLoginController extends Controller
{
    public static function enabled(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public function redirect()
    {
        abort_unless(self::enabled(), 404);

        return Socialite::driver('google')->redirect();
    }

    public function callback()
    {
        abort_unless(self::enabled(), 404);

        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect()->route('login')->withErrors(['google' => 'Signing in with Google did not work. Try again, or use your email and password.']);
        }

        $email = Str::lower((string) $google->getEmail());

        // Only trust an address Google itself has verified: linking on an unverified
        // address would let someone take over the account that owns it.
        if ($email === '' || ! ($google->user['email_verified'] ?? false)) {
            return redirect()->route('login')->withErrors(['google' => 'Your Google account has no verified email address. Use your email and password instead.']);
        }

        $user = User::where('google_id', $google->getId())->first()
            ?? User::where('email', $email)->first();

        if ($user && ! $user->hasVerifiedEmail()) {
            // Someone registered this address without proving they own it, and its real
            // owner has just proven it through Google. Drop everything that sign-up could
            // still use: password, remember-me cookie, API keys and open sessions.
            $user->forceFill([
                'password' => Str::random(64),
                'has_password' => false,
                'remember_token' => null,
                'google_id' => $google->getId(),
                'email_verified_at' => now(),
            ])->save();
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
        } elseif ($user) {
            $user->forceFill(['google_id' => $user->google_id ?? $google->getId()])->save();
        } elseif (! \App\Support\Mode::registrationOpen()) {
            // A self-hosted install that takes no new accounts: Google signs existing ones in, never makes one.
            return redirect()->route('login')->withErrors(['google' => \App\Support\Mode::registrationClosedMessage()]);
        } else {
            $user = User::create([
                'name' => $google->getName() ?: Str::before($email, '@'),
                'email' => $email,
                // Never used: the account signs in through Google, or sets a password via "Forgot password".
                'password' => Str::random(64),
            ]);
            // The button carries the terms notice, so pressing it is the acceptance. A self-hosted install has no terms.
            $user->forceFill(['google_id' => $google->getId(), 'email_verified_at' => now(), 'terms_accepted_at' => \App\Support\Mode::hosted() ? now() : null, 'has_password' => false])->save();
            event(new Registered($user));
        }

        Auth::login($user, remember: true);
        request()->session()->regenerate();

        return redirect()->intended(route('forms.index'));
    }
}
