<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** A refresh token for a signed-in MCP client. Only its SHA-256 is stored; each use replaces it. */
class OAuthRefreshToken extends Model
{
    use MassPrunable;

    public const DAYS = 60;

    protected $table = 'oauth_refresh_tokens';

    protected $fillable = ['token_hash', 'client_id', 'user_id', 'access_token_id', 'code_id', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now()->subDay())
            ->orWhere('revoked_at', '<', now()->subDays(30));
    }
}
