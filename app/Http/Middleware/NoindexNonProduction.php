<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends X-Robots-Tag: noindex on every response when APP_ENV is not
 * production. Protects staging / preview / misconfigured deploys from being
 * indexed and competing with the real domain as duplicate content.
 *
 * Registered globally in bootstrap/app.php (start-new-project.sh does this
 * automatically; if it warned, add it yourself):
 *
 *     ->withMiddleware(function (Middleware $middleware) {
 *         $middleware->append(\App\Http\Middleware\NoindexNonProduction::class);
 *     })
 */
class NoindexNonProduction
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // SITE_NOINDEX keeps a live production site out of search until it is ready.
        if (! app()->environment('production') || config('app.noindex')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
