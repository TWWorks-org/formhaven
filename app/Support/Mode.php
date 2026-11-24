<?php

namespace App\Support;

use App\Models\User;

/**
 * The one switch between the paid hosted service and a self-hosted install (config/formhaven.php).
 * Routes read it when they are registered, so a mode change needs a restart (and a fresh
 * `php artisan optimize` where routes and config are cached).
 */
final class Mode
{
    public const HOSTED = 'hosted';

    public const SELF_HOSTED = 'self_hosted';

    /** The code an agent gets from POST /api/v1/start when nobody new may sign up here. */
    public const REGISTRATION_CLOSED = 'REGISTRATION_CLOSED';

    public static function hosted(): bool
    {
        return config('formhaven.mode') === self::HOSTED;
    }

    public static function selfHosted(): bool
    {
        return ! self::hosted();
    }

    /**
     * Whether a new account may be made. Hosted: always. Self-hosted: while the install has no
     * account yet (its owner signs up first), and after that only with ALLOW_REGISTRATION=true.
     */
    public static function registrationOpen(): bool
    {
        return self::hosted() || config('formhaven.allow_registration') || ! User::query()->exists();
    }

    public static function registrationClosedMessage(): string
    {
        return 'Registration is closed on this install: it already has its account. Whoever runs it can set ALLOW_REGISTRATION=true in its .env to let others sign up.';
    }
}
