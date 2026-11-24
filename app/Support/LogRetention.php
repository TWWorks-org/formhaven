<?php

namespace App\Support;

/**
 * Deletes dated log files older than config logging.retention_days, the days the privacy policy
 * gives for application logs. The daily driver alone keeps a number of FILES, not days: a channel
 * that writes once a fortnight would keep a file for months.
 */
class LogRetention
{
    /** Deletes laravel-YYYY-MM-DD.log and security-YYYY-MM-DD.log older than the limit; returns how many. */
    public static function prune(?string $dir = null, ?int $days = null): int
    {
        $dir ??= storage_path('logs');
        $days ??= (int) config('logging.retention_days');
        $oldest = now()->startOfDay()->subDays($days)->toDateString();
        $deleted = 0;

        foreach (glob($dir.'/*.log') ?: [] as $file) {
            if (preg_match('/^(laravel|security)-(\d{4}-\d{2}-\d{2})\.log$/', basename($file), $m) && $m[2] < $oldest && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
