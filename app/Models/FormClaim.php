<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A form address an agent reserved through POST /api/v1/start, waiting for the site owner to activate it. */
class FormClaim extends Model
{
    use MassPrunable;

    /** An unclaimed reservation lapses after this many days, and its address stops answering. */
    public const DAYS = 7;

    protected $fillable = ['public_id', 'name', 'site_url', 'redirect_url', 'retention_days', 'client', 'claim_token_hash', 'setup_key_hash', 'setup_key_prefix', 'ip_hash'];

    protected function casts(): array
    {
        return ['claimed_at' => 'datetime', 'retention_days' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isClaimed(): bool
    {
        return $this->claimed_at !== null;
    }

    public function isExpired(): bool
    {
        return ! $this->isClaimed() && $this->created_at->lt(now()->subDays(self::DAYS));
    }

    public function expiresAt(): \Illuminate\Support\Carbon
    {
        return $this->created_at->copy()->addDays(self::DAYS);
    }

    /** Waiting reservations still inside their days. */
    public function scopeWaiting(Builder $query): Builder
    {
        return $query->whereNull('claimed_at')->where('created_at', '>=', now()->subDays(self::DAYS));
    }

    public static function findByClaimToken(string $token): ?self
    {
        return static::where('claim_token_hash', hash('sha256', $token))->first();
    }

    /** Claimed rows stay as the record of which form came through the agent flow; lapsed ones go. */
    public function prunable(): Builder
    {
        return static::whereNull('claimed_at')->where('created_at', '<', now()->subDays(self::DAYS));
    }
}
