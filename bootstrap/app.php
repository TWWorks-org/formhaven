<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append(\App\Http\Middleware\NoindexNonProduction::class);
        // The visitor's own IP behind Cloudflare and Caddy, read from config at request time.
        $middleware->replace(\Illuminate\Http\Middleware\TrustProxies::class, \App\Http\Middleware\TrustProxies::class);
        // Form endpoints receive posts from other people's sites, so there is no CSRF token.
        // Stripe signs its webhooks instead.
        $middleware->validateCsrfTokens(except: ['f/*', 'stripe/*']);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Agents calling the API or MCP always get JSON errors, whatever Accept header they send.
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*', 'mcp'));

        // An agent's setup key that ran out says so and how to go on, instead of a bare "Unauthenticated."
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            return $request->is('api/*', 'mcp') ? \App\Support\ExpiredSetupKey::response($request) : null;
        });
    })->create();
