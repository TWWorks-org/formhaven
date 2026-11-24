<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** One account's real submissions and most form units in one month: what the limits read and what the monthly charge bills. */
class MonthlyUsage extends Model
{
    protected $table = 'monthly_usage';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['charged_at' => 'datetime'];
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function monthOf(CarbonInterface $at): string
    {
        return $at->format('Y-m');
    }

    public static function count(int $userId, CarbonInterface $at): int
    {
        return (int) static::where('user_id', $userId)->where('month', static::monthOf($at))->value('submissions');
    }

    /**
     * Add (or, with a negative delta, take away) submissions for the month of $at; never below zero.
     * With $units (the account's form units now), also raise the month's peak_units to it, so the
     * month's pool never shrinks when forms are deleted later. One atomic statement.
     */
    public static function add(int $userId, CarbonInterface $at, int $delta = 1, ?int $units = null): void
    {
        $now = now();
        $update = ['submissions' => DB::raw('max(0, monthly_usage.submissions + ('.(int) $delta.'))'), 'updated_at' => $now];

        if ($units !== null) {
            $update['peak_units'] = DB::raw('max(monthly_usage.peak_units, '.(int) $units.')');
        }

        static::query()->upsert(
            [['user_id' => $userId, 'month' => static::monthOf($at), 'submissions' => max(0, $delta), 'peak_units' => max(0, (int) $units), 'created_at' => $now, 'updated_at' => $now]],
            ['user_id', 'month'],
            $update,
        );
    }
}
