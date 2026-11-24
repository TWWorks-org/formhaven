{{-- The pricing page's full price list. Every rate from config/billing.php; every yearly figure says "a year" next to it, and the yearly cells say what they save, so a yearly price never reads as dearer than a monthly one. Below sm each row stacks, with its column name in front of each figure (data-label), so no price hides off screen. --}}
@php
    $p = config('billing.per_form');
    $bill = \App\Support\Bill::class;
    // "2 months free" only when the full and the lower rate both save the same whole number of months.
    $free = 12 - $p['yearly_eur'] / $p['monthly_eur'];
    $freeLower = 12 - $p['lower_yearly_eur'] / $p['lower_monthly_eur'];
    $monthsFree = abs($free - round($free)) < 1e-9 && abs($free - $freeLower) < 1e-9 && $free >= 1 ? (int) round($free) : null;
    $saves = $monthsFree ? $monthsFree.' '.($monthsFree === 1 ? 'month' : 'months').' free' : null;
    $head = 'border-b border-zinc-900 px-5 py-3 font-medium';
    $row = 'border-zinc-200 not-last:border-b max-sm:block max-sm:py-3';
    $what = 'px-5 py-4 align-top font-normal max-sm:block max-sm:pb-2';
    $figure = 'px-5 py-4 align-top text-right font-mono text-[0.9375rem] sm:whitespace-nowrap max-sm:flex max-sm:items-baseline max-sm:justify-between max-sm:gap-4 max-sm:py-1.5 max-sm:before:font-mono max-sm:before:text-[0.6875rem] max-sm:before:tracking-[0.1em] max-sm:before:text-zinc-600 max-sm:before:uppercase max-sm:before:shrink-0 max-sm:before:whitespace-nowrap max-sm:before:content-[attr(data-label)] max-sm:text-sm';
@endphp
<div class="overflow-x-auto rounded border border-zinc-900 bg-white">
    <table class="w-full text-left tabular-nums max-sm:block">
        <caption class="sr-only">The price list</caption>
        <thead class="bg-zinc-50 font-mono text-[0.6875rem] tracking-[0.1em] text-zinc-600 uppercase max-sm:hidden">
            <tr><th scope="col" class="{{ $head }}">What</th><th scope="col" class="{{ $head }} text-right">Billed monthly</th><th scope="col" class="{{ $head }} text-right">Billed yearly</th></tr>
        </thead>
        <tbody class="max-sm:block">
            <tr class="{{ $row }}">
                <th scope="row" class="{{ $what }}"><strong class="font-semibold">Forms 1 to {{ $p['full_price_forms'] }}</strong><span class="block text-sm text-zinc-600">Each includes {{ number_format($p['submissions']) }} submissions a month</span></th>
                <td data-label="Billed monthly" class="{{ $figure }}"><span>{{ $bill::format($p['monthly_eur']) }} per form a month</span></td>
                <td data-label="Billed yearly" class="{{ $figure }}"><span>{{ $bill::format($p['yearly_eur']) }} per form a year @if ($saves)<span class="mt-0.5 block font-sans text-xs font-medium text-green-800">{{ $saves }}</span>@endif</span></td>
            </tr>
            <tr class="{{ $row }}">
                <th scope="row" class="{{ $what }}"><strong class="font-semibold">Form {{ $p['full_price_forms'] + 1 }} and every form after it</strong><span class="block text-sm text-zinc-600">Still {{ number_format($p['submissions']) }} submissions a month each</span></th>
                <td data-label="Billed monthly" class="{{ $figure }}"><span>{{ $bill::format($p['lower_monthly_eur']) }} per form a month</span></td>
                <td data-label="Billed yearly" class="{{ $figure }}"><span>{{ $bill::format($p['lower_yearly_eur']) }} per form a year @if ($saves)<span class="mt-0.5 block font-sans text-xs font-medium text-green-800">{{ $saves }}</span>@endif</span></td>
            </tr>
            <tr class="{{ $row }}">
                <th scope="row" class="{{ $what }}"><strong class="font-semibold">Each started 1,000 submissions past your pool</strong><span class="block text-sm text-zinc-600">Charged once the month has ended, the same on both</span></th>
                <td colspan="2" data-label="Monthly or yearly" class="{{ $figure }}"><span>{{ $bill::format($p['extra_eur']) }} per started 1,000</span></td>
            </tr>
            <tr class="{{ $row }}">
                <th scope="row" class="{{ $what }}"><strong class="font-semibold">Smallest bill</strong><span class="block text-sm text-zinc-600">For at least {{ $p['min_forms'] }} forms, so 1 to {{ $p['min_forms'] }} forms cost the same</span></th>
                <td data-label="Billed monthly" class="{{ $figure }}"><span>{{ $bill::format($bill::monthly($p['min_forms'])) }} a month</span></td>
                <td data-label="Billed yearly" class="{{ $figure }}"><span>{{ $bill::format($bill::yearly($p['min_forms'])) }} a year</span></td>
            </tr>
        </tbody>
    </table>
</div>
