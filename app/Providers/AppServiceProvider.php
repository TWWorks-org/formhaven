<?php

namespace App\Providers;

use App\Mail\CloudflareTransport;
use App\Support\SecurityLog;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // A card that fails on a renewal, a yearly add-on or the monthly extra-usage invoice puts
        // the subscription "past due". Cashier would switch the account off at that moment, over
        // as little as EUR 1. The forms keep working while Stripe retries the card; they stop when
        // Stripe gives up and cancels the subscription (Stripe dashboard, Billing, retry settings).
        \Laravel\Cashier\Cashier::keepPastDueSubscriptionsActive();

        // Stripe's webhook route (POST /stripe/webhook) exists on the hosted service only. Set both
        // ways on every boot: the flag is static, so a self-hosted boot must not carry over to the next.
        \Laravel\Cashier\Cashier::$registersRoutes = \App\Support\Mode::hosted();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Per visitor per form: enough for a human, too slow for a flood.
        RateLimiter::for('submit', fn (Request $request) => Limit::perMinute(10)
            ->by($request->ip().'|'.$request->route('publicId'))
            ->response(fn () => response()->json([
                'ok' => false,
                'message' => 'Too many submissions from this address. Wait a minute and try again.',
            ], 429)));

        // Some hosts block outbound SMTP ports, so this mailer sends over Cloudflare's
        // HTTPS API instead (App\Mail\CloudflareTransport). A missing credential fails loudly at send time.
        Mail::extend('cloudflare', function () {
            $account = trim((string) config('services.cloudflare_email.account_id'));
            $token = trim((string) config('services.cloudflare_email.token'));

            if ($account === '' || $token === '') {
                throw new \RuntimeException('The cloudflare mailer needs CLOUDFLARE_ACCOUNT_ID and CLOUDFLARE_EMAIL_TOKEN.');
            }

            return new CloudflareTransport($account, $token);
        });

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->id ?: $request->ip()));

        // A recipient's messages page needs no login, so it is held per address: paging through a
        // busy form is fine, trying link after link is not.
        RateLimiter::for('recipient-messages', fn (Request $request) => Limit::perMinute(30)
            ->by('recipient-messages:'.$request->ip())
            ->response(fn () => response()->view('recipient', [
                'state' => 'notice',
                'title' => 'Too many pages at once',
                'text' => 'Wait a minute, then open the page again.',
            ], 429)));

        // The start flow needs no account, so it is held per address and across everyone.
        $startRefused = fn (string $message) => fn () => response()->json(['message' => $message], 429);
        RateLimiter::for('start', fn (Request $request) => [
            Limit::perHour(10)->by('start:'.$request->ip())
                ->response($startRefused('Too many forms started from this address. Try again in an hour, or create the form with an API key.')),
            Limit::perDay(500)->by('start:all')
                ->response($startRefused('Too many forms were started today. Try again tomorrow, or create the form with an API key.')),
        ]);

        // Session rows store no IP (App\Session\DatabaseSessionHandler): same driver name, so no .env change.
        \Illuminate\Support\Facades\Session::extend('database', function ($app) {
            $config = $app['config']['session'];

            return new \App\Session\DatabaseSessionHandler($app['db']->connection($config['connection']), $config['table'], $config['lifetime'], $app);
        });

        // Sign-ins for the security log (App\Support\SecurityLog). Only the account's id, never the typed address.
        \Illuminate\Support\Facades\Event::listen(fn (\Illuminate\Auth\Events\Login $e) => SecurityLog::event('login.succeeded', ['user_id' => $e->user->getAuthIdentifier(), 'guard' => $e->guard]));
        \Illuminate\Support\Facades\Event::listen(fn (\Illuminate\Auth\Events\Failed $e) => SecurityLog::event('login.failed', ['user_id' => $e->user?->getAuthIdentifier(), 'guard' => $e->guard]));
        \Illuminate\Support\Facades\Event::listen(fn (\Illuminate\Auth\Events\Lockout $e) => SecurityLog::event('login.throttled'));
        \Illuminate\Support\Facades\Event::listen(fn (\Illuminate\Auth\Events\PasswordReset $e) => SecurityLog::event('password.reset', ['user_id' => $e->user->getAuthIdentifier()]));

        // Our own wording for Laravel's two account emails, in place of "Hello!" and "Regards".
        VerifyEmail::toMailUsing(fn (object $notifiable, string $url) => (new MailMessage)
            ->subject('Confirm your email for FormHaven')
            ->greeting('Confirm your email address')
            ->line('Confirm that '.$notifiable->getEmailForVerification().' is yours, so we can send your form submissions to it.')
            ->action('Confirm email address', $url)
            ->line('If you did not make a FormHaven account, ignore this email.'));

        ResetPassword::toMailUsing(fn (object $notifiable, string $token) => (new MailMessage)
            ->subject('Reset your FormHaven password')
            ->greeting('Reset your password')
            ->line('Someone asked to reset the password for '.$notifiable->getEmailForPasswordReset().'. The link works for '.config('auth.passwords.'.config('auth.defaults.passwords').'.expire').' minutes.')
            ->action('Choose a new password', url(route('password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()], false)))
            ->line('If that was not you, ignore this email: your password stays the same.'));
    }
}
