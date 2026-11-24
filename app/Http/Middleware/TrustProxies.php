<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * Which proxies may tell Laravel the visitor's IP (config app.trusted_proxies, TRUSTED_PROXIES).
 * Unset by default, so an install that faces the internet directly cannot be fooled by a header.
 *
 * The hosted service sets "*": requests come Cloudflare, Caddy, PHP, and Caddy keeps an incoming
 * X-Forwarded-For only from Cloudflare's ranges (its global trusted_proxies) and appends the
 * address it got the request from. Laravel then takes the entry left of that one, which
 * Cloudflare wrote, so a header the visitor makes up stays further left and is ignored. Only the
 * Forwarded-For header is trusted: the scheme and host come from Caddy itself. Without this,
 * every per-IP limit saw a few Cloudflare addresses instead of visitors, so one attacker could
 * throttle everyone. Cloudflare's ranges are not listed here: Caddy holds them and filters.
 */
class TrustProxies extends Middleware
{
    protected $headers = Request::HEADER_X_FORWARDED_FOR;

    protected function proxies()
    {
        return config('app.trusted_proxies') ?: null;
    }
}
