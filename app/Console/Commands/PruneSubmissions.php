<?php

namespace App\Console\Commands;

use App\Models\Form;
use Illuminate\Console\Command;

class PruneSubmissions extends Command
{
    protected $signature = 'submissions:prune';

    protected $description = 'Delete submissions older than their form\'s retention period';

    public function handle(): int
    {
        $deleted = 0;

        Form::query()->each(function (Form $form) use (&$deleted) {
            $deleted += $form->submissions()
                ->where('created_at', '<', now()->subDays($form->retention_days))
                ->delete();
        });

        $this->info("Deleted {$deleted} expired submissions.");

        return self::SUCCESS;
    }
}
