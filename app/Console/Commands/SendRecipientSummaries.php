<?php

namespace App\Console\Commands;

use App\Mail\DeliveryRefused;
use App\Mail\RecipientSummary;
use App\Models\FormRecipient;
use App\Models\User;
use App\Support\MailBudget;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * The weekly count to each confirmed recipient of a form: last week's real messages (Monday to
 * Sunday, Europe/Amsterdam) and the link to their messages page. Runs every morning after the
 * owner's Monday summary: Monday's run sends nearly all of them, and the cap per run (the mail
 * quota is shared with other sites) leaves the rest for the next mornings. summary_week moves
 * only for those queued, so nobody gets the same week twice and nobody left over is skipped.
 */
class SendRecipientSummaries extends Command
{
    public const MAX_PER_RUN = 50;

    public const TIMEZONE = 'Europe/Amsterdam';

    protected $signature = 'digest:recipients {--form= : Only this form (its public id)} {--dry-run : Count who would get one, send nothing}';

    protected $description = 'Email each confirmed recipient the weekly count of their form\'s messages';

    public function handle(): int
    {
        $weekStart = now(self::TIMEZONE)->startOfWeek(Carbon::MONDAY)->subWeek();
        $from = $weekStart->copy()->utc();
        $to = $weekStart->copy()->addWeek()->utc();
        $week = $weekStart->toDateString();

        $sent = $held = $skippedForCap = 0;
        $accounts = [];

        FormRecipient::query()
            ->whereNotNull('confirmed_at')
            // Confirmed after the week ended: none of last week's messages were theirs.
            ->where('confirmed_at', '<', $to)
            ->whereNull('summary_stopped_at')
            ->where(fn ($q) => $q->whereNull('summary_week')->orWhere('summary_week', '<', $week))
            // The email's job is the link to the messages page, so it needs that page on.
            ->whereHas('form', fn ($q) => $q->where('recipients_can_view', true)->where('recipients_weekly_summary', true)
                ->when($this->option('form'), fn ($q, $id) => $q->where('public_id', $id)))
            // Our provider refused the address before: mailing it again only hurts the sending reputation.
            ->whereDoesntHave('deliveryFailures', fn ($q) => $q->whereIn('kind', [DeliveryRefused::BOUNCED, DeliveryRefused::SUPPRESSED]))
            ->with('form.user')
            // By id, not by offset: queuing one moves summary_week, which takes it out of this query.
            ->lazyById(200)
            ->each(function (FormRecipient $recipient) use ($from, $to, $week, &$sent, &$held, &$skippedForCap, &$accounts) {
                $form = $recipient->form;

                // The same checks that hold a message's emails (app/Actions/ReceiveSubmission.php):
                // no plan, a form beyond the plan, or a trial past its emails for the month.
                $accounts[$form->user_id] ??= $this->emailsHeld($form->user);
                if ($accounts[$form->user_id] || ! $form->withinPlan()) {
                    $held++;

                    return;
                }

                // Only what came in while this address was on the form.
                $since = $recipient->confirmed_at->gt($from) ? $recipient->confirmed_at : $from;
                $count = $form->submissions()->where('spam', false)
                    ->where('created_at', '>=', $since)->where('created_at', '<', $to)->count();

                if ($count === 0) {
                    return;
                }

                // The run's cap, then the day's summary mail budget, shared with the owners' Monday summary.
                if ($sent >= self::MAX_PER_RUN || (! $this->option('dry-run') && ! MailBudget::tryTake(MailBudget::SUMMARIES))) {
                    $skippedForCap++;

                    return;
                }

                $sent++;

                if ($this->option('dry-run')) {
                    return;
                }

                $recipient->forceFill(['summary_week' => $week])->save();
                // Pruning has already removed the start of the week on a form that keeps fewer days.
                $partial = $since->lt(now()->subDays($form->retention_days));

                try {
                    Mail::to($recipient->email)->queue(new RecipientSummary($recipient, $count, $week, $partial));
                } catch (TransportExceptionInterface) {
                    // Only on a synchronous queue: RecipientSummary::failed() has logged it, and the run goes on.
                }
            });

        $verb = $this->option('dry-run') ? 'Would send' : 'Queued';
        $this->info("{$verb} {$sent} weekly counts to recipients for the week of {$week}."
            .($held ? " {$held} held: the account's emails are paused." : '')
            .($skippedForCap ? " {$skippedForCap} left for the next run (cap ".self::MAX_PER_RUN.' a run, '.MailBudget::limit(MailBudget::SUMMARIES).' summary emails a day).' : ''));

        if ($skippedForCap) {
            Log::warning('Weekly counts to recipients over the cap', ['left' => $skippedForCap, 'cap' => self::MAX_PER_RUN]);
        }

        return self::SUCCESS;
    }

    private function emailsHeld(User $owner): bool
    {
        return $owner->plan() === null || $owner->emailsPaused();
    }
}
