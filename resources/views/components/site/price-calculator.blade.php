@props(['forms' => 25, 'messages' => 8000, 'yearly' => true])
{{-- The pricing page's bill calculator. The server renders the bill for the default input from App\Support\Bill, so the page is right with JavaScript off; the script below redoes the same sum from the rates in #price-rates (config/billing.php, never typed here) as the reader types. Keep the script's sum() in step with Bill::sum() and its money() with Bill::format(). --}}
@php
    $p = config('billing.per_form');
    $bill = \App\Support\Bill::class;
    $rates = \Illuminate\Support\Arr::only($p, ['monthly_eur', 'yearly_eur', 'lower_monthly_eur', 'lower_yearly_eur', 'full_price_forms', 'min_forms', 'submissions', 'extra_eur']);
    // "2 months free" only when the full and the lower rate both save the same whole number of months.
    $free = 12 - $p['yearly_eur'] / $p['monthly_eur'];
    $freeLower = 12 - $p['lower_yearly_eur'] / $p['lower_monthly_eur'];
    $monthsFree = abs($free - round($free)) < 1e-9 && abs($free - $freeLower) < 1e-9 && $free >= 1 ? (int) round($free) : null;
    $period = $yearly ? 'yearly' : 'monthly';
    $per = $yearly ? 'per form a year' : 'per form a month';
    $units = max($p['min_forms'], $forms);
    $full = min($units, $p['full_price_forms']);
    $lower = $units - $full;
    $fullRate = $p["{$period}_eur"];
    $lowerRate = $p["lower_{$period}_eur"];
    $pool = $units * $p['submissions'];
    $extra = $messages > $pool ? (int) ceil(($messages - $pool) / $p['submissions']) : 0;
    $total = $yearly ? $bill::yearly($forms) : $bill::monthly($forms);
    $a = $yearly ? 'a year' : 'a month';
    $switch = 'min-h-11 px-4 text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900 aria-pressed:bg-zinc-900 aria-pressed:text-white';
    $field = 'h-12 rounded border border-zinc-900 bg-white px-3 text-right font-mono text-xl font-medium tabular-nums focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900';
@endphp
<section id="calculator" data-price-calculator class="flex flex-wrap overflow-hidden rounded border border-zinc-900">
    <div class="flex min-w-0 flex-[3_1_30rem] flex-col gap-8 bg-zinc-50 p-6 sm:p-9">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h2 class="text-2xl font-semibold tracking-tight">Work out your bill</h2>
            <div role="group" aria-label="Billing period" class="flex rounded border border-zinc-400 bg-white">
                <button type="button" data-period="monthly" aria-pressed="{{ $yearly ? 'false' : 'true' }}" class="{{ $switch }} rounded-l-[3px]">Monthly</button>
                <button type="button" data-period="yearly" aria-pressed="{{ $yearly ? 'true' : 'false' }}" class="{{ $switch }} rounded-r-[3px] border-l border-zinc-400">Yearly{{ $monthsFree ? ', '.$monthsFree.' '.($monthsFree === 1 ? 'month' : 'months').' free' : '' }}</button>
            </div>
        </div>

        <div class="flex flex-col gap-3">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <label for="price-forms" class="font-semibold">How many forms do you run?<span class="block text-sm font-normal text-zinc-600">Usually one per client site.</span></label>
                <input id="price-forms" type="number" inputmode="numeric" min="1" step="1" value="{{ $forms }}" class="{{ $field }} w-28">
            </div>
            <input id="price-forms-range" type="range" aria-label="Number of forms" min="1" max="200" step="1" value="{{ min(200, $forms) }}" class="h-11 w-full accent-zinc-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900">
            <div class="flex flex-wrap gap-2" role="group" aria-label="Common form counts">
                @foreach ([1, 5, 10, 25, 50, 100] as $preset)
                    <button type="button" data-forms="{{ $preset }}" aria-pressed="{{ $preset === $forms ? 'true' : 'false' }}" class="min-h-11 min-w-14 rounded border border-zinc-400 bg-white px-3.5 font-mono text-sm tabular-nums focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-zinc-900 aria-pressed:border-zinc-900 aria-pressed:bg-zinc-900 aria-pressed:text-white">{{ $preset }}</button>
                @endforeach
            </div>
            <p id="price-forms-note" class="text-sm text-amber-900" hidden></p>
        </div>

        <div class="flex flex-col gap-3 border-t border-zinc-300 pt-7">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <label for="price-messages" class="font-semibold">Real messages in a busy month<span class="block text-sm font-normal text-zinc-600">Across all forms. Spam never counts.</span></label>
                <input id="price-messages" type="number" inputmode="numeric" min="0" step="500" value="{{ $messages }}" class="{{ $field }} w-36">
            </div>
            <p id="price-messages-note" class="text-sm text-amber-900" hidden></p>
            <p class="max-w-2xl text-sm text-zinc-700">Your forms include <strong id="price-pool" class="font-mono tabular-nums">{{ number_format($pool) }}</strong> submissions a month, shared across them. Past that every message is still emailed, and each started 1,000 is {{ $bill::format($p['extra_eur']) }}.</p>
        </div>
    </div>

    <div class="flex min-w-0 flex-[2_1_24rem] flex-col gap-6 bg-zinc-900 p-6 text-zinc-100 sm:p-9">
        <p class="font-mono text-xs tracking-[0.12em] text-zinc-300 uppercase">Your bill</p>
        <div aria-live="polite" class="flex flex-col gap-4">
            <p><span id="price-total" class="block text-6xl leading-none font-semibold tracking-tight text-white tabular-nums sm:text-[4rem]">{{ $bill::format($total) }}</span> <span id="price-unit" class="mt-2 block text-zinc-300">{{ $a }} for {{ $forms }} {{ $forms === 1 ? 'form' : 'forms' }}</span></p>
            <p id="price-min-note" class="text-sm text-zinc-300" @if ($forms >= $p['min_forms']) hidden @endif>The smallest bill is the price of {{ $p['min_forms'] }} forms, with {{ number_format($p['min_forms'] * $p['submissions']) }} submissions a month included.</p>
            <p id="price-extra-note" class="text-sm text-amber-300" @if ($extra === 0) hidden @endif>@if ($extra > 0)In a month with {{ number_format($messages) }} messages you pay {{ $bill::format($extra * $p['extra_eur']) }} extra for that month: {{ $extra }} × {{ $bill::format($p['extra_eur']) }} for each started 1,000 past {{ number_format($pool) }}.@endif</p>
        </div>
        <ul id="price-lines" class="border-t border-zinc-700 font-mono text-sm tabular-nums">
            <li class="flex justify-between gap-4 border-b border-zinc-700 py-3"><span class="text-zinc-200">{{ $full }} {{ $full === 1 ? 'form' : 'forms' }} × {{ $bill::format($fullRate) }} {{ $per }}</span> <span data-subtotal class="whitespace-nowrap text-white">{{ $bill::format($full * $fullRate) }} {{ $a }}</span></li>
            @if ($lower > 0)
                <li class="flex justify-between gap-4 border-b border-zinc-700 py-3"><span class="text-zinc-200">{{ $lower }} {{ $lower === 1 ? 'form' : 'forms' }} × {{ $bill::format($lowerRate) }} {{ $per }}</span> <span data-subtotal class="whitespace-nowrap text-white">{{ $bill::format($lower * $lowerRate) }} {{ $a }}</span></li>
            @endif
            <li class="flex justify-between gap-4 border-b border-zinc-700 py-3"><span class="text-zinc-200">Submissions included</span> <span class="whitespace-nowrap text-white">{{ number_format($pool) }} a month</span></li>
        </ul>
        <a href="{{ route('register') }}" class="mt-auto rounded bg-white px-5 py-3.5 text-center font-medium text-zinc-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">Start {{ config('billing.trial_days') }}-day free trial</a>
        <p class="-mt-3 text-center text-sm text-zinc-300">No card. Up to {{ config('billing.trial_forms') }} forms while you try it.</p>
    </div>
</section>
<script type="application/json" id="price-rates">@json($rates)</script>
<script>
    (() => {
        const root = document.querySelector('[data-price-calculator]');
        const R = JSON.parse(document.getElementById('price-rates').textContent);
        if (!root || !R) return;
        const $ = (id) => document.getElementById(id);
        const formsIn = $('price-forms'), range = $('price-forms-range'), msgIn = $('price-messages');
        const state = { forms: {{ (int) $forms }}, messages: {{ (int) $messages }}, yearly: {{ $yearly ? 'true' : 'false' }} };
        // As Bill::format: no cents on whole amounts, always two digits otherwise (20, 17.50). The euro sign is
        // built from its code so the copy tests, which read the page's text, never see a sign without an amount.
        const money = (eur) => { const c = Math.round(eur * 100); return String.fromCharCode(8364) + (c % 100 === 0 ? String(c / 100) : (c / 100).toFixed(2)); };
        const num = (n) => n.toLocaleString('en-US');
        const plural = (n) => n + (n === 1 ? ' form' : ' forms');
        const note = (el, text) => { el.textContent = text || ''; el.hidden = !text; };

        // A whole number from a field, or null with the reason it was not one.
        const read = (raw, min, what) => {
            const v = String(raw).trim();
            if (v === '') return { value: null, why: 'Type the number of ' + what + '.' };
            const n = Number(v);
            if (!Number.isFinite(n)) return { value: null, why: 'Type the number of ' + what + ' as digits.' };
            if (n < min) return { value: min, why: 'The lowest is ' + min + ', so this shows ' + min + '.' };
            if (!Number.isInteger(n)) return { value: Math.ceil(n), why: 'Counted as ' + num(Math.ceil(n)) + ': ' + what + ' come in whole numbers.' };
            return { value: n, why: '' };
        };

        const render = () => {
            const { forms, messages, yearly } = state;
            const period = yearly ? 'yearly' : 'monthly';
            const a = yearly ? 'a year' : 'a month';
            const per = yearly ? 'per form a year' : 'per form a month';
            // Bill::sum: never fewer than min_forms, the first full_price_forms at the full rate, the rest lower.
            const units = Math.max(R.min_forms, forms);
            const full = Math.min(units, R.full_price_forms);
            const lower = units - full;
            const fullRate = R[period + '_eur'], lowerRate = R['lower_' + period + '_eur'];
            const pool = units * R.submissions;
            const extra = messages > pool ? Math.ceil((messages - pool) / R.submissions) : 0;

            $('price-total').textContent = money(full * fullRate + lower * lowerRate);
            $('price-unit').textContent = a + ' for ' + plural(forms);
            $('price-pool').textContent = num(pool);
            $('price-min-note').hidden = forms >= R.min_forms;
            note($('price-extra-note'), extra > 0 ? 'In a month with ' + num(messages) + ' messages you pay ' + money(extra * R.extra_eur) + ' extra for that month: ' + extra + ' × ' + money(R.extra_eur) + ' for each started 1,000 past ' + num(pool) + '.' : '');

            const rows = [[plural(full) + ' × ' + money(fullRate) + ' ' + per, money(full * fullRate) + ' ' + a]];
            if (lower > 0) rows.push([plural(lower) + ' × ' + money(lowerRate) + ' ' + per, money(lower * lowerRate) + ' ' + a]);
            rows.push(['Submissions included', num(pool) + ' a month']);
            const list = $('price-lines');
            const template = list.firstElementChild;
            list.replaceChildren(...rows.map(([label, amount]) => {
                const li = template.cloneNode(true);
                li.children[0].textContent = label;
                li.children[1].textContent = amount;
                return li;
            }));

            root.querySelectorAll('[data-period]').forEach((b) => b.setAttribute('aria-pressed', String((b.dataset.period === 'yearly') === yearly)));
            root.querySelectorAll('[data-forms]').forEach((b) => b.setAttribute('aria-pressed', String(Number(b.dataset.forms) === forms)));
        };

        const setForms = (raw, from) => {
            const r = read(raw, 1, 'forms');
            note($('price-forms-note'), r.why);
            if (r.value === null) return;
            state.forms = r.value;
            if (from !== range) range.value = String(Math.min(200, r.value));
            if (from !== formsIn) formsIn.value = String(r.value);
            render();
        };

        formsIn.addEventListener('input', () => setForms(formsIn.value, formsIn));
        formsIn.addEventListener('change', () => { if (formsIn.value.trim() !== '' && Number.isFinite(Number(formsIn.value))) formsIn.value = String(state.forms); });
        range.addEventListener('input', () => setForms(range.value, range));
        root.querySelectorAll('[data-forms]').forEach((b) => b.addEventListener('click', () => setForms(b.dataset.forms)));
        root.querySelectorAll('[data-period]').forEach((b) => b.addEventListener('click', () => { state.yearly = b.dataset.period === 'yearly'; render(); }));
        msgIn.addEventListener('input', () => {
            const r = read(msgIn.value, 0, 'messages');
            note($('price-messages-note'), r.why);
            if (r.value === null) return;
            state.messages = r.value;
            render();
        });
        render();
    })();
</script>
