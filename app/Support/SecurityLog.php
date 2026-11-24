<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * One line per security event (logins, password and email changes, API keys, account mail
 * limits, account deletion) in the "security" log channel, deleted after the days the privacy
 * policy gives for application logs (config logging.retention_days, LogRetention).
 *
 * Account holders only: never a password, a token or a key, and never anything about the visitors
 * of a customer's form (the DPA promises their IP is kept only as a keyed hash). The IP is the
 * account holder's own (App\Http\Middleware\TrustProxies), which the privacy policy lists under
 * server logs for security. Writing never stops the request it describes.
 */
class SecurityLog
{
    /** @param array<string, scalar|null> $context */
    public static function event(string $event, array $context = []): void
    {
        try {
            Log::channel('security')->info($event, $context + ['ip' => request()->ip()]);
        } catch (\Throwable) {
            // A log file that cannot be written must not turn a login into an error page.
        }
    }
}
