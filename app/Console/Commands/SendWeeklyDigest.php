<?php

namespace App\Console\Commands;

use App\Mail\WeeklyDigest;
use App\Models\User;
use App\Support\MailBudget;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The Monday summary. Capped per run because the mail quota is shared with other sites; whoever
 * is left over gets theirs on the next run, since digest_sent_at only moves for those sent.
 */
class SendWeeklyDigest extends Command
{
    public const MAX_PER_RUN = 100;

    protected $signature = 'digest:send {--user= : Only this user id} {--dry-run : Count who would get one, send nothing}';

    protected $description = 'Email each account owner the weekly summary of their forms';

    public function handle(): int
    {
        $sent = $skippedForCap = 0;

        User::query()
            ->where('weekly_digest', true)
            ->whereNotNull('email_verified_at')
            ->where(fn ($q) => $q->whereNull('digest_sent_at')->orWhere('digest_sent_at', '<', now()->subDays(6)))
            ->whereHas('forms', fn ($q) => $q->where('messages_total', '>', 0))
            ->when($this->option('user'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')
            ->each(function (User $user) use (&$sent, &$skippedForCap) {
                if ($user->plan() === null) {
                    return;
                }

                // The run's cap, then the day's summary mail budget (config mail.budget.summaries_per_day).
                if ($sent >= self::MAX_PER_RUN || (! $this->option('dry-run') && ! MailBudget::tryTake(MailBudget::SUMMARIES))) {
                    $skippedForCap++;

                    return;
                }

                $sent++;

                if (! $this->option('dry-run')) {
                    $user->forceFill(['digest_sent_at' => now()])->save();
                    Mail::to($user->email)->queue(new WeeklyDigest($user));
                }
            });

        $verb = $this->option('dry-run') ? 'Would send' : 'Queued';
        $this->info("{$verb} {$sent} weekly summaries.".($skippedForCap ? " {$skippedForCap} left for the next run (cap ".self::MAX_PER_RUN.' a run, '.MailBudget::limit(MailBudget::SUMMARIES).' summary emails a day).' : ''));

        if ($skippedForCap) {
            Log::warning('Weekly summaries over the cap', ['left' => $skippedForCap, 'cap' => self::MAX_PER_RUN]);
        }

        return self::SUCCESS;
    }
}
