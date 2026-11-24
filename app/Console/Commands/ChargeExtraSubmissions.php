<?php

namespace App\Console\Commands;

use App\Models\MonthlyUsage;
use App\Models\User;
use App\Support\ExtraUsageBiller;
use Illuminate\Console\Command;
use Laravel\Cashier\Exceptions\IncompletePayment;

/**
 * Bills last month's submissions past the pool the account's forms include, at one extra unit
 * per started thousand (config/billing.php), so the bill is the higher of forms and thousands.
 *
 * Runs daily but only touches last month's rows that were never charged: a Stripe outage on
 * the 1st is retried the next day, and a row is marked charged as soon as Stripe has the
 * charge (ExtraUsageBiller: a line on the next regular invoice for a monthly plan, an invoice
 * straight away for a yearly one; paid or not, since a failed card is then Stripe's dunning,
 * not a second invoice).
 */
class ChargeExtraSubmissions extends Command
{
    protected $signature = 'billing:charge-extra {--dry-run : Show what would be charged, charge nothing}';

    protected $description = 'Charge last month\'s submissions past what each paying account\'s forms include';

    public function handle(ExtraUsageBiller $biller): int
    {
        $last = now()->subMonthNoOverflow();
        $month = MonthlyUsage::monthOf($last);
        $price = config('billing.per_form.price_extra');
        $dry = (bool) $this->option('dry-run');

        if (! $dry && blank($price)) {
            $this->error('STRIPE_PRICE_EXTRA_SUBMISSIONS is not set: nothing charged.');

            return self::FAILURE;
        }

        MonthlyUsage::where('month', $month)->whereNull('charged_at')->with('user')->each(function (MonthlyUsage $usage) use ($biller, $dry, $month, $last) {
            /** @var User|null $user */
            $user = $usage->user;

            if (! $user || $user->isOwner() || ! $user->isPaying()) {
                return;
            }

            // The pool is the month's own: the most form units it had, not the forms left today.
            $extra = $user->extraUnits($usage->submissions, $last);
            $this->line("{$user->email}: {$usage->submissions} submissions in {$month}, {$user->poolUnits($last)} form units, {$extra} extra.");

            if ($dry) {
                return;
            }

            if ($extra > 0) {
                try {
                    $biller->charge($user, $extra, $month);
                } catch (IncompletePayment) {
                    // The invoice exists and Stripe retries the card; charging again would bill twice.
                } catch (\Throwable $e) {
                    report($e);

                    return;
                }
            }

            $usage->forceFill(['charged_units' => $extra, 'charged_at' => now()])->save();
        });

        return self::SUCCESS;
    }
}
