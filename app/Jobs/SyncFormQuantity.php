<?php

namespace App\Jobs;

use App\Models\MonthlyUsage;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sets the account's Stripe quantity to its form units after a form is created or deleted.
 * Queued, so a slow or failing Stripe call never stops a form being created; dispatched with
 * a delay (billing.per_form.sync_delay_minutes, an hour) and unique per account until it starts,
 * so an agent creating ten forms in a row makes one call, a form created and deleted inside
 * that hour never changes the bill, and a form added while it runs queues the next. It reads
 * the count when it runs, never when it was queued, so the last run always leaves the right number.
 *
 * Monthly: the change is prorated onto the next invoice. Yearly: invoiced straight away,
 * because the next invoice can be up to a year off.
 */
class SyncFormQuantity implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 3600];

    public function __construct(public int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(): void
    {
        $user = User::find($this->userId);

        if (! $user || ! $user->isPaying() || $user->isOwner()) {
            return;
        }

        $subscription = $user->subscription('default');
        $units = $user->formUnits();

        if ((int) $subscription->quantity === $units) {
            return;
        }

        if ($subscription->stripe_price === config('billing.per_form.price_yearly')) {
            $subscription->alwaysInvoice();
        }

        $subscription->updateQuantity($units);

        // Only now is the new quantity billed, so only now may it raise the month's pool.
        MonthlyUsage::add($user->id, now(), 0, $units);
    }
}
