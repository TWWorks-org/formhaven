<?php

namespace App\Http\Middleware;

use App\Support\Mode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The register page on a self-hosted install that already has its account (App\Support\Mode).
 * A person gets a page that says so; anything asking for JSON gets the API's error shape.
 */
class EnsureRegistrationOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Mode::registrationOpen()) {
            return $next($request);
        }

        return $request->expectsJson()
            ? response()->json(['message' => Mode::registrationClosedMessage(), 'code' => Mode::REGISTRATION_CLOSED], 403)
            : response()->view('registration-closed', [], 403);
    }
}
