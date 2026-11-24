<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Where a webhook may go. The owner types the URL and our server makes the request, so
 * without this check a form could make us call localhost, the Tailscale network or a
 * cloud metadata address. The host is resolved once, every address is checked, and the
 * request is then pinned to the checked address so DNS cannot change it in between.
 */
class WebhookTarget
{
    /** Overridable in tests so they do not depend on real DNS. @var null|\Closure(string): list<string> */
    public static ?\Closure $resolver = null;

    private const BLOCKED_V4 = [
        ['100.64.0.0', 10],  // carrier-grade NAT, used by Tailscale
        ['192.0.0.0', 24],   // IETF protocol assignments
        ['198.18.0.0', 15],  // benchmarking
    ];

    private const OWN_SERVER = 'The webhook address points to FormHaven\'s own server. Use the address of the service that should receive the submissions.';

    public function __construct(public readonly string $url, public readonly string $host, public readonly int $port, public readonly string $ip) {}

    /** @throws InvalidArgumentException with a sentence the owner can act on */
    public static function check(string $url): self
    {
        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host']) || preg_match('/[\\\\\s\x00-\x1f\x7f]/', $url)) {
            throw new InvalidArgumentException('The webhook address must be a full https:// URL.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Put credentials for the webhook in its path or query, not before the host.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $isIp = (bool) filter_var($host, FILTER_VALIDATE_IP);

        // Only plain ASCII names with no trailing dot: the name we check and pin must be the
        // exact name curl looks up, or curl resolves it again on its own and the check is moot.
        if (! $isIp && ! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/', $host)) {
            throw new InvalidArgumentException('The webhook host name is not valid. Use a plain ASCII host name (for an international domain, its xn-- form) without a trailing dot.');
        }

        // Our own host and its subdomains, by name: behind a proxy the name does not resolve to the box.
        $appHost = self::appHost();
        if ($appHost !== '' && ($host === $appHost || str_ends_with($host, '.'.$appHost))) {
            throw new InvalidArgumentException(self::OWN_SERVER);
        }

        $ips = $isIp ? [$host] : (self::$resolver ?? self::dns(...))($host);

        if ($ips === []) {
            throw new InvalidArgumentException("The webhook host {$host} could not be found.");
        }

        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                throw new InvalidArgumentException('The webhook address points to a private or local network. Use a public https:// URL.');
            }
        }

        // Our own public addresses are public too, but a webhook to them would make this server call
        // itself (a form's webhook posting to another form's address, or to the box's other sites).
        $own = self::ownAddresses();
        if (array_filter($ips, fn ($ip) => self::inAny($ip, $own))) {
            throw new InvalidArgumentException(self::OWN_SERVER);
        }

        return new self($url, $host, (int) ($parts['port'] ?? 443), $ips[0]);
    }

    /**
     * This server's addresses: what APP_URL's host resolves to (unless services.webhooks.block_app_url_ips
     * is off, for a site behind a proxy whose shared addresses are not the box's), plus the IPs or CIDR
     * ranges in services.webhooks.blocked_ips (the box's own addresses, which APP_URL may not name).
     *
     * @return list<string>
     */
    public static function ownAddresses(): array
    {
        $host = self::appHost();
        $resolved = match (true) {
            $host === '' || ! config('services.webhooks.block_app_url_ips', true) => [],
            (bool) filter_var($host, FILTER_VALIDATE_IP) => [$host],
            default => (self::$resolver ?? self::dns(...))($host),
        };

        return array_values(array_unique([...$resolved, ...config('services.webhooks.blocked_ips', [])]));
    }

    private static function appHost(): string
    {
        return strtolower(trim((string) parse_url((string) config('app.url'), PHP_URL_HOST), '[]'));
    }

    /** Whether $ip is one of $ranges: plain IPs, or CIDR ranges such as 203.0.113.0/24 and 2001:db8::/48. */
    private static function inAny(string $ip, array $ranges): bool
    {
        $bytes = @inet_pton($ip);

        foreach ($ranges as $range) {
            [$network, $bits] = str_contains($range, '/') ? explode('/', $range, 2) : [$range, null];
            $net = @inet_pton(trim($network, '[] '));

            if ($bytes === false || $net === false || strlen($net) !== strlen($bytes)) {
                continue;
            }

            $bits = $bits === null ? strlen($net) * 8 : max(0, min(strlen($net) * 8, (int) $bits));
            $whole = intdiv($bits, 8);

            if (substr($bytes, 0, $whole) !== substr($net, 0, $whole)) {
                continue;
            }

            $rest = $bits % 8;
            if ($rest === 0 || ((ord($bytes[$whole]) ^ ord($net[$whole])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
                return true;
            }
        }

        return false;
    }

    /** The curl option that makes the request connect to the checked address. */
    public function pin(): array
    {
        $ip = str_contains($this->ip, ':') ? "[{$this->ip}]" : $this->ip;

        return [CURLOPT_RESOLVE => ["{$this->host}:{$this->port}:{$ip}"]];
    }

    public static function isPublic(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // Only global unicast (2000::/3). That also rules out every form that carries an IPv4
            // address inside (::ffff:0:0/96, 64:ff9b::/96, ::/96); 6to4 (2002::/16) sits inside
            // 2000::/3 and wraps one too, so it is refused by name.
            $bytes = inet_pton($ip);

            return (ord($bytes[0]) & 0xE0) === 0x20 && substr($bytes, 0, 2) !== "\x20\x02";
        }

        foreach (self::BLOCKED_V4 as [$network, $bits]) {
            $mask = -1 << (32 - $bits);
            if ((ip2long($ip) & $mask) === (ip2long($network) & $mask)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function dns(string $host): array
    {
        $v4 = gethostbynamel($host) ?: [];
        $v6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_values(array_unique([...$v4, ...$v6]));
    }
}
