<?php

use App\Support\AdminStats;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * The owner's view of the whole business: accounts, the path to paying, agent starts, delivery
 * problems and abuse reports. Only accounts in OWNER_EMAILS see it; everyone else gets a 404,
 * so the page does not tell anyone it exists. It never shows what a visitor typed.
 */
new class extends Component {
    public function mount(): void
    {
        abort_unless(Auth::user()?->isOwner(), 404);
    }

    public function with(AdminStats $stats): array
    {
        $accounts = $stats->accounts();

        return [
            'accounts' => $accounts,
            'totals' => $stats->totals($accounts),
            'funnel' => $stats->funnel($accounts),
            'messages7' => $stats->messages(7),
            'messages28' => $stats->messages(28),
            'agents7' => $stats->agentStarts(7),
            'agents28' => $stats->agentStarts(28),
            'failures' => $stats->deliveryFailures(),
            'reports' => $stats->abuseReports(),
            // A self-hosted install has no billing: no paying, revenue or trial figures (app/Support/Mode.php).
            'hosted' => \App\Support\Mode::hosted(),
        ];
    }
}; ?>

@php
    $card = 'rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900';
    $th = 'px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500';
    $td = 'px-4 py-3 align-top';
@endphp

<div class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl" level="1">Admin</flux:heading>
        @if ($hosted)
            <flux:subheading>Every account on FormHaven. The counts leave out owner accounts, so they show outside customers only. What visitors typed is never shown here.</flux:subheading>
        @else
            <flux:subheading>Every account on this install. The counts leave out owner accounts (OWNER_EMAILS). What visitors typed is never shown here.</flux:subheading>
        @endif
    </div>

    <section aria-label="Totals" class="grid gap-3 sm:grid-cols-2 {{ $hosted ? 'xl:grid-cols-4' : '' }}">
        <div class="{{ $card }}">
            <p class="text-sm text-zinc-500">Accounts</p>
            <p class="mt-1 text-3xl font-semibold tabular-nums">{{ number_format($totals['accounts']) }}</p>
            <p class="mt-1 text-sm text-zinc-500">{{ $totals['new_7d'] }} new in 7 days, {{ $totals['new_28d'] }} in 28</p>
        </div>
        @if ($hosted)
        <div class="{{ $card }}">
            <p class="text-sm text-zinc-500">Paying</p>
            <p class="mt-1 text-3xl font-semibold tabular-nums">{{ number_format($totals['paying']) }}</p>
            <p class="mt-1 text-sm text-zinc-500">{{ $totals['trialing'] }} on a trial now</p>
        </div>
        <div class="{{ $card }}">
            <p class="text-sm text-zinc-500">Monthly revenue</p>
            <p class="mt-1 text-3xl font-semibold tabular-nums">€{{ number_format($totals['mrr']) }}</p>
            <p class="mt-1 text-sm text-zinc-500">At list prices, a yearly plan as a twelfth</p>
        </div>
        @endif
        <div class="{{ $card }}">
            <p class="text-sm text-zinc-500">Real messages</p>
            <p class="mt-1 text-3xl font-semibold tabular-nums">{{ number_format($messages7['real']) }}</p>
            <p class="mt-1 text-sm text-zinc-500">in 7 days ({{ number_format($messages28['real']) }} in 28), {{ number_format($messages7['spam']) }} spam caught</p>
        </div>
    </section>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="{{ $card }}">
            <flux:heading size="lg" level="2">{{ $hosted ? 'From signup to paying' : 'From signup to the first message' }}</flux:heading>
            @php($top = max(1, reset($funnel)))
            <ol class="mt-4 grid gap-3">
                @foreach ($funnel as $step => $n)
                    <li>
                        <div class="flex justify-between gap-3 text-sm"><span>{{ $step }}</span><span class="font-medium tabular-nums">{{ number_format($n) }}</span></div>
                        <div class="mt-1 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800" aria-hidden="true"><div class="h-full rounded-full bg-zinc-900 dark:bg-white" style="width: {{ round($n / $top * 100) }}%"></div></div>
                    </li>
                @endforeach
            </ol>
        </section>

        <section class="{{ $card }}">
            <flux:heading size="lg" level="2">Forms started by agents</flux:heading>
            <dl class="mt-4 grid grid-cols-[1fr_auto_auto] gap-x-6 gap-y-2 text-sm tabular-nums">
                <dt class="text-zinc-500"></dt><dd class="text-zinc-500">7 days</dd><dd class="text-zinc-500">28 days</dd>
                <dt>Started</dt><dd>{{ $agents7['started'] }}</dd><dd>{{ $agents28['started'] }}</dd>
                <dt>Activated by the site owner</dt><dd>{{ $agents7['activated'] }}</dd><dd>{{ $agents28['activated'] }}</dd>
                <dt>Activated and got a real message</dt><dd>{{ $agents7['with_message'] }}</dd><dd>{{ $agents28['with_message'] }}</dd>
            </dl>
            @if ($agents28['clients'])
                <p class="mt-4 text-sm text-zinc-500">Agents, last 28 days: {{ collect($agents28['clients'])->map(fn ($n, $name) => $name.' '.$n)->implode(', ') }}</p>
            @else
                <p class="mt-4 text-sm text-zinc-500">No agent has started a form in the last 28 days.</p>
            @endif
        </section>
    </div>

    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
        <div class="p-5"><flux:heading size="lg" level="2">Accounts</flux:heading></div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[52rem] text-sm">
                <thead class="border-y border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <tr><th class="{{ $th }}">Account</th><th class="{{ $th }}">Signed up</th><th class="{{ $th }}">Status</th><th class="{{ $th }} text-right">Forms</th><th class="{{ $th }} text-right">Messages, 30 days</th><th class="{{ $th }}">Last message</th><th class="{{ $th }} text-right">Not delivered, 28 days</th></tr>
                </thead>
                <tbody>
                    @foreach ($accounts as $a)
                        <tr class="border-b border-zinc-100 last:border-b-0 dark:border-zinc-800" wire:key="account-{{ $a->user->id }}">
                            <td class="{{ $td }}"><span class="font-medium">{{ $a->user->email }}</span><br><span class="text-zinc-500">{{ $a->user->name }}</span></td>
                            <td class="{{ $td }} whitespace-nowrap">{{ $a->user->created_at->format('j M Y') }}</td>
                            <td class="{{ $td }}">{{ $a->status }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $a->forms }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ number_format($a->messages_30d) }}</td>
                            <td class="{{ $td }} whitespace-nowrap">{{ $a->last_message_at ? \Illuminate\Support\Carbon::parse($a->last_message_at)->diffForHumans() : 'Never' }}</td>
                            <td class="{{ $td }} text-right tabular-nums">{{ $a->failures_28d }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <div class="grid gap-6 xl:grid-cols-2">
        <section class="{{ $card }}">
            <flux:heading size="lg" level="2">Emails that did not arrive</flux:heading>
            @forelse ($failures as $failure)
                <div class="mt-4 border-t border-zinc-100 pt-3 text-sm first-of-type:border-t-0 first-of-type:pt-0 dark:border-zinc-800" wire:key="failure-{{ $failure->id }}">
                    <p class="font-medium">{{ $failure->form?->name }} <span class="font-normal text-zinc-500">· {{ $failure->form?->user?->email }} · {{ $failure->created_at->diffForHumans() }}</span></p>
                    <p class="text-zinc-600 dark:text-zinc-400">{{ $failure->isOwners() ? 'To the account owner.' : 'To a client recipient.' }} {{ $failure->explain() }}</p>
                </div>
            @empty
                <p class="mt-4 text-sm text-zinc-500">None recorded.</p>
            @endforelse
        </section>

        <section class="{{ $card }}">
            <flux:heading size="lg" level="2">Abuse reports</flux:heading>
            @forelse ($reports as $report)
                <div class="mt-4 border-t border-zinc-100 pt-3 text-sm first-of-type:border-t-0 first-of-type:pt-0 dark:border-zinc-800" wire:key="report-{{ $report->id }}">
                    <p class="font-medium break-all">{{ $report->url }} <span class="font-normal text-zinc-500">· {{ $report->created_at->diffForHumans() }}</span></p>
                    <p class="whitespace-pre-line text-zinc-600 dark:text-zinc-400">{{ \Illuminate\Support\Str::limit($report->reason, 300) }}</p>
                </div>
            @empty
                <p class="mt-4 text-sm text-zinc-500">None received.</p>
            @endforelse
        </section>
    </div>
</div>
