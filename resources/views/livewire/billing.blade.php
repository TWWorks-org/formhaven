<?php

use App\Support\Bill;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    /** One price per form (config/billing.php): the quantity is the account's form units, kept in step by SyncFormQuantity. */
    public function subscribe(string $interval): void
    {
        $priceId = config('billing.per_form.price_'.($interval === 'yearly' ? 'yearly' : 'monthly'));

        abort_if(blank($priceId), 404);

        $options = [
            'success_url' => route('billing').'?subscribed=1',
            'cancel_url' => route('billing'),
            // Cards only: later automatic charges (form changes, extra usage) need a method that
            // can be charged without the customer present.
            'payment_method_types' => ['card'],
        ];

        if (config('billing.automatic_tax')) {
            $options += [
                'automatic_tax' => ['enabled' => true],
                'customer_update' => ['address' => 'auto'],
                'billing_address_collection' => 'required',
            ];
        }

        $user = Auth::user();
        $checkout = $user
            ->newSubscription('default', $priceId)
            ->quantity($user->formUnits())
            ->allowPromotionCodes()
            ->checkout($options);

        $this->redirect($checkout->url);
    }

    public function manage(): void
    {
        $this->redirect(Auth::user()->billingPortalUrl(route('billing')));
    }

    public function with(): array
    {
        $user = Auth::user();
        $price = config('billing.per_form');
        $units = $user->formUnits();
        $subscription = $user->subscription('default');

        return [
            'user' => $user,
            'plan' => $user->plan(),
            'price' => $price,
            'paying' => $user->isPaying(),
            'yearlyPlan' => $subscription?->stripe_price === $price['price_yearly'],
            'formsUsed' => $user->forms()->count(),
            'units' => $units,
            'monthlyBill' => Bill::format(Bill::monthly($units)),
            'yearlyBill' => Bill::format(Bill::yearly($units)),
            'submissionsUsed' => $user->submissionsThisMonth(),
            'extraSoFar' => $user->extraUnits($user->submissionsThisMonth()),
        ];
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">Billing</flux:heading>
        <flux:subheading>{{ \App\Support\Bill::format($price['monthly_eur']) }} per form a month, {{ \App\Support\Bill::format($price['lower_monthly_eur']) }} from the {{ \App\Support\Bill::firstLowerForm() }} form, every feature included. Submissions are stored in Germany and a data processing agreement comes with it.</flux:subheading>
    </div>

    @if (request('subscribed'))
        <div class="rounded-xl border border-zinc-200 bg-white p-4 text-sm dark:border-zinc-700 dark:bg-zinc-900">
            Thanks. Your subscription is active as soon as Stripe confirms the payment, usually within a minute.
        </div>
    @endif

    <x-plan-status />

    @if ($plan)
        <section class="flex flex-wrap items-start gap-6 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex-[1_1_260px]">
                <flux:heading size="lg" level="2">
                    @if ($plan['key'] === 'owner') Owner account
                    @elseif ($paying) {{ $yearlyPlan ? $yearlyBill : $monthlyBill }} {{ $yearlyPlan ? 'a year' : 'a month' }}
                    @else Free trial
                    @endif
                </flux:heading>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    @if ($plan['key'] === 'owner')
                        No bill: this account runs the business's own sites.
                    @elseif ($paying)
                        {{ $units }} form {{ $units === 1 ? 'unit' : 'units' }}: {{ $formsUsed }} {{ $formsUsed === 1 ? 'form' : 'forms' }}{{ $formsUsed < $price['min_forms'] ? ', billed as the minimum of '.$price['min_forms'] : '' }}. Adding or deleting a form changes the bill from that day.
                    @else
                        {{ max(1, (int) ceil(now()->diffInDays($user->trial_ends_at))) }} days left. Subscribe any time to keep your forms running after that.
                    @endif
                </p>
                @if ($paying)
                    <flux:button class="mt-4" variant="primary" wire:click="manage">Card, invoices or cancel</flux:button>
                @endif
            </div>
            <div class="grid flex-[2_1_420px] gap-5 sm:grid-cols-2">
                <div>
                    <div class="flex justify-between gap-2 text-sm"><span class="text-zinc-600 dark:text-zinc-400">Forms</span><span class="tabular-nums">{{ $formsUsed }}{{ $plan['forms'] ? ' of '.$plan['forms'] : '' }}</span></div>
                    @if ($plan['forms'])
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800" aria-hidden="true"><div class="h-full rounded-full bg-zinc-900 dark:bg-white" style="width: {{ min(100, round($formsUsed / $plan['forms'] * 100)) }}%"></div></div>
                    @endif
                </div>
                <div>
                    <div class="flex justify-between gap-2 text-sm"><span class="text-zinc-600 dark:text-zinc-400">Submissions this month</span><span class="tabular-nums">{{ number_format($submissionsUsed) }} of {{ number_format($plan['submissions']) }} included</span></div>
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800" aria-hidden="true"><div class="h-full rounded-full bg-zinc-900 dark:bg-white" style="width: {{ min(100, round($submissionsUsed / max(1, $plan['submissions']) * 100)) }}%"></div></div>
                    @if ($paying && $extraSoFar > 0)
                        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ number_format($extraSoFar * $price['submissions']) }} extra so far: {{ \App\Support\Bill::format($extraSoFar * $price['extra_eur']) }}, added to your next invoice once the month ends.</p>
                    @endif
                </div>
            </div>
        </section>
    @endif

    @unless ($paying || $user->isOwner())
        <section class="flex flex-col gap-4 rounded-xl border-2 border-zinc-900 bg-white p-6 dark:border-white dark:bg-zinc-900" x-data="{ yearly: true }">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading size="lg" level="2">Subscribe</flux:heading>
                <div class="inline-flex rounded-lg bg-zinc-100 p-[3px] dark:bg-zinc-800" role="group" aria-label="Billing period">
                    <button type="button" x-on:click="yearly = true" x-bind:aria-pressed="yearly" x-bind:class="yearly ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white' : 'text-zinc-600 dark:text-zinc-400'" class="min-h-8 rounded-md px-3 text-sm font-medium">Yearly (2 months free)</button>
                    <button type="button" x-on:click="yearly = false" x-bind:aria-pressed="! yearly" x-bind:class="! yearly ? 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white' : 'text-zinc-600 dark:text-zinc-400'" class="min-h-8 rounded-md px-3 text-sm font-medium">Monthly</button>
                </div>
            </div>
            <div>
                <p x-show="yearly"><span class="text-3xl font-semibold tracking-tight">{{ $yearlyBill }}</span> <span class="text-sm text-zinc-500">a year for your {{ $formsUsed }} {{ $formsUsed === 1 ? 'form' : 'forms' }}{{ $formsUsed < $price['min_forms'] ? ', billed as the minimum of '.$price['min_forms'] : '' }}</span></p>
                <p x-show="! yearly" x-cloak><span class="text-3xl font-semibold tracking-tight">{{ $monthlyBill }}</span> <span class="text-sm text-zinc-500">a month for your {{ $formsUsed }} {{ $formsUsed === 1 ? 'form' : 'forms' }}{{ $formsUsed < $price['min_forms'] ? ', billed as the minimum of '.$price['min_forms'] : '' }}</span></p>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ \App\Support\Bill::format($price['monthly_eur']) }} per form a month or {{ \App\Support\Bill::format($price['yearly_eur']) }} per form a year, for at least {{ $price['min_forms'] }} forms. From the {{ \App\Support\Bill::firstLowerForm() }} form, {{ \App\Support\Bill::format($price['lower_monthly_eur']) }} per form a month or {{ \App\Support\Bill::format($price['lower_yearly_eur']) }} per form a year. Every form includes {{ number_format($price['submissions']) }} submissions a month, shared across your forms; each extra 1,000 is {{ \App\Support\Bill::format($price['extra_eur']) }}. Spam never counts.</p>
            </div>
            <ul class="grid gap-2 text-sm sm:grid-cols-2">
                <li class="flex gap-2"><flux:icon.check class="mt-0.5 size-4 flex-none" />Add or delete forms any time: the bill follows</li>
                <li class="flex gap-2"><flux:icon.check class="mt-0.5 size-4 flex-none" />Every message emailed, never cut off</li>
                <li class="flex gap-2"><flux:icon.check class="mt-0.5 size-4 flex-none" />Spam filter, webhooks, client copies, Monday summary</li>
                <li class="flex gap-2"><flux:icon.check class="mt-0.5 size-4 flex-none" />Stored in Germany, DPA included</li>
            </ul>
            <div>
                <flux:button variant="primary" x-show="yearly" wire:click="subscribe('yearly')">Subscribe for {{ $yearlyBill }} a year</flux:button>
                <flux:button variant="primary" x-show="! yearly" x-cloak wire:click="subscribe('monthly')">Subscribe for {{ $monthlyBill }} a month</flux:button>
            </div>
            <p class="text-sm text-zinc-500">Payments run through Stripe. Change your card, see invoices or cancel any time from Stripe's secure billing page.</p>
        </section>
    @endunless
</div>
