<?php

namespace App\Support;

use App\Models\User;

/**
 * The Stripe calls behind the monthly extra-usage charge: $units of the one-off "Extra 1,000
 * submissions" price. On a monthly plan the line waits for the subscription's next regular
 * invoice, so the customer gets one invoice and one card charge a month. On a yearly plan that
 * invoice can be up to a year off, so it is invoiced straight away. Its own class so tests can
 * swap it for a fake. A price (not a bare amount) because Cashier's default currency is USD and
 * Stripe Tax needs one.
 *
 * The line and the invoice belong to the subscription, not to the bare customer: Checkout saves
 * the card on the subscription only, so an invoice without one has no card to charge (seen in
 * test mode on an account that subscribed through Checkout).
 */
class ExtraUsageBiller
{
    public function charge(User $user, int $units, string $month): void
    {
        $subscription = $user->subscription('default');

        // The line and the invoice are two calls. If the second failed yesterday, the line is still
        // waiting at Stripe: adding it again would bill the month twice.
        $waiting = collect($user->stripe()->invoiceItems->all(['customer' => $user->stripe_id, 'pending' => true, 'limit' => 100])->data)
            ->contains(fn ($item) => ($item->metadata['extra_usage_month'] ?? null) === $month);

        if (! $waiting) {
            $user->tabPrice(config('billing.per_form.price_extra'), $units, [
                'subscription' => $subscription->stripe_id,
                'description' => "Extra submissions in {$month}: {$units} x 1,000",
                'metadata' => ['extra_usage_month' => $month],
            ]);
        }

        // Monthly: Stripe puts the pending line on the subscription's next regular invoice.
        if ($subscription->stripe_price === config('billing.per_form.price_monthly')) {
            return;
        }

        // auto_advance: without it Stripe never retries a card that fails on this invoice
        // (seen in test mode: the invoice stayed open with no next attempt).
        $subscription->invoice(['auto_advance' => true]);
    }
}
