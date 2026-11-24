<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * A self-hosted Umami, configured in config/services.php (UMAMI_WEBSITE_ID,
 * UMAMI_SCRIPT_URL, UMAMI_COLLECT_URL). With no website id, no tracker loads
 * and no event is sent.
 *
 * The browser tracker (resources/views/partials/analytics.blade.php) records
 * page views, load times and errors on public and sign-in pages. This class
 * sends the few events a browser cannot see, from the server, at the moment
 * they really happen (a signup that passed validation, not a button click).
 *
 * Every event goes to the configured collect URL with the visitor's own IP and
 * browser, so it joins their visit; Umami hashes those into a session ID and
 * stores neither. It never carries a name, an email or an account ID, and
 * nothing is sent while someone is signed in, as the privacy policy says.
 * Same guards as the tracker: only APP_URL's host sends (data-domains), and a
 * browser's Do Not Track turns it off.
 */
final class Umami
{
    /** Event data keys that could ever name a person are dropped. */
    private const BLOCKED_KEYS = '/email|name|token|password|secret|phone|address|user|(^|_)id$/i';

    /** The Umami website id, or null when analytics is off for this install. */
    public static function websiteId(): ?string
    {
        return trim((string) config('services.umami.website_id')) ?: null;
    }

    /** The tracker script URL, or null when the tracker must not load (no website id or no script). */
    public static function scriptUrl(): ?string
    {
        return self::websiteId() === null ? null : (trim((string) config('services.umami.script_url')) ?: null);
    }

    /** The only host that sends: APP_URL's. */
    public static function domain(): string
    {
        return (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    /** Sends one event after the response, so it can never slow or break the page. */
    public static function track(?Request $request, string $name, string $path, array $data = []): void
    {
        // Production web requests only: artisan and queue workers carry APP_URL
        // as their request, not a visitor. Tests switch it on with
        // services.umami.send_in_tests.
        $live = app()->isProduction() && ! app()->runningInConsole();
        if (! $live && config('services.umami.send_in_tests') !== true) {
            return;
        }
        $collect = trim((string) config('services.umami.collect_url'));
        $payload = $collect === '' ? null : self::payload($request, $name, $path, $data);
        if ($payload === null) {
            return;
        }

        dispatch(function () use ($collect, $payload) {
            try {
                Http::timeout(3)->acceptJson()->post($collect, ['type' => 'event', 'payload' => $payload]);
            } catch (\Throwable) {
                // Analytics must never matter to the visitor.
            }
        })->afterResponse();
    }

    /** The collect payload, or null when nothing may be sent. Public for tests. */
    public static function payload(?Request $request, string $name, string $path, array $data = []): ?array
    {
        $website = self::websiteId();
        if ($website === null || $request === null || $request->getHost() !== self::domain()) {
            return null;
        }
        if ($request->header('DNT') === '1' || $request->user() !== null) {
            return null;
        }
        $userAgent = (string) $request->userAgent();
        if ($userAgent === '') {
            return null;
        }

        $clean = [];
        foreach ($data as $key => $value) {
            if (is_scalar($value) && preg_match(self::BLOCKED_KEYS, (string) $key) !== 1) {
                $clean[(string) $key] = is_string($value) ? mb_substr($value, 0, 100) : $value;
            }
        }

        return array_filter([
            'website' => $website,
            'hostname' => self::domain(),
            'url' => 'https://'.self::domain().'/'.ltrim($path, '/'),
            'name' => $name,
            'data' => $clean ?: null,
            'ip' => $request->header('CF-Connecting-IP') ?: $request->ip(),
            'userAgent' => $userAgent,
            'language' => $request->getPreferredLanguage() ?: null,
        ], fn ($v) => $v !== null);
    }

    /** How an account was made, from the request that made it: google, github, api or email. */
    public static function signupMethod(?Request $request): string
    {
        $path = $request?->path() ?? '';
        foreach (['google', 'github'] as $provider) {
            if (str_contains($path, $provider)) {
                return $provider;
            }
        }

        return str_starts_with($path, 'api/') ? 'api' : 'email';
    }
}
