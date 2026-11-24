<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** A one-time authorization code from the consent screen. Only its SHA-256 is stored. */
class OAuthCode extends Model
{
    use MassPrunable;

    /** Seconds a code stays usable: the client exchanges it straight after the redirect. */
    public const LIFETIME = 120;

    protected $table = 'oauth_codes';

    protected $fillable = ['code_hash', 'client_id', 'user_id', 'redirect_uri', 'code_challenge', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now()->subDay());
    }
}
