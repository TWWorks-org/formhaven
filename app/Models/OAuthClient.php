<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An MCP client (Claude, ChatGPT, Claude Code) that registered itself to sign people in. */
class OAuthClient extends Model
{
    use MassPrunable;

    protected $table = 'oauth_clients';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'redirect_uris'];

    protected function casts(): array
    {
        return ['redirect_uris' => 'array'];
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(OAuthRefreshToken::class, 'client_id');
    }

    /**
     * Exact match, except that a loopback address (Claude Code, other desktop clients) matches
     * on any port: RFC 8252 section 7.3, and Claude's connector docs ask for the same on localhost.
     */
    public function allowsRedirect(string $uri): bool
    {
        if (! self::unambiguous($uri)) {
            return false;
        }

        foreach ($this->redirect_uris as $registered) {
            if (hash_equals($registered, $uri) || (self::isLoopback($registered) && self::withoutPort($registered) === self::withoutPort($uri))) {
                return true;
            }
        }

        return false;
    }

    /**
     * A redirect URI that PHP's parser and a browser read the same way: no backslash, whitespace,
     * control character or user@ part, and a well-formed host. Without this, an address such as
     * "http://localhost\@evil.example/" could pass a check here and send the code elsewhere.
     */
    public static function unambiguous(string $uri): bool
    {
        if (preg_match('/[\\\\\s\x00-\x1f\x7f]/', $uri) || str_contains($uri, '@')) {
            return false;
        }

        $parts = parse_url($uri);
        $host = trim((string) ($parts['host'] ?? ''), '[]');

        return $parts !== false && ! isset($parts['user'], $parts['pass'])
            && (filter_var($host, FILTER_VALIDATE_IP) || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME));
    }

    public static function isLoopback(string $uri): bool
    {
        $parts = parse_url($uri);

        return ($parts['scheme'] ?? null) === 'http' && in_array($parts['host'] ?? null, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    }

    private static function withoutPort(string $uri): ?string
    {
        $parts = parse_url($uri);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /** Registrations nobody ever signed in with, after 30 days. Claude registers a new one per connection. */
    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(30))->whereDoesntHave('refreshTokens');
    }
}
