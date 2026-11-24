{{-- Sample bills for common form counts, from App\Support\Bill, so a reader or an agent without JavaScript still gets real totals. Below sm each row stacks, with its column name in front of each figure (data-label). --}}
@php
    $p = config('billing.per_form');
    $bill = \App\Support\Bill::class;
    $head = 'border-b border-zinc-900 px-5 py-3 font-medium';
    $cell = 'px-5 py-3.5 text-right whitespace-nowrap max-sm:flex max-sm:justify-between max-sm:gap-4 max-sm:py-1 max-sm:before:font-mono max-sm:before:text-[0.6875rem] max-sm:before:tracking-[0.1em] max-sm:before:text-zinc-600 max-sm:before:uppercase max-sm:before:content-[attr(data-label)]';
@endphp
<div class="overflow-x-auto rounded border border-zinc-900 bg-white">
    <table class="w-full text-left tabular-nums max-sm:block max-sm:[&>tbody]:block">
        <caption class="sr-only">What your bill comes to</caption>
        <thead class="bg-zinc-50 font-mono text-[0.6875rem] tracking-[0.1em] text-zinc-600 uppercase max-sm:hidden">
            <tr><th scope="col" class="{{ $head }}">Forms</th><th scope="col" class="{{ $head }} text-right">Submissions included a month</th><th scope="col" class="{{ $head }} text-right">Billed monthly</th><th scope="col" class="{{ $head }} text-right">Billed yearly</th></tr>
        </thead>
        <tbody>
            @foreach ([$p['min_forms'], $p['full_price_forms'], 25, 50, 100] as $forms)
                <tr class="border-zinc-200 font-mono text-[0.9375rem] not-last:border-b max-sm:block max-sm:py-3">
                    <td class="px-5 py-3.5 font-medium whitespace-nowrap max-sm:block max-sm:pb-1.5 max-sm:font-sans max-sm:font-semibold max-sm:after:content-['_forms']">{{ $forms === $p['min_forms'] ? '1 to '.$forms : $forms }}</td>
                    <td data-label="Included a month" class="{{ $cell }} text-zinc-700">{{ number_format($forms * $p['submissions']) }}</td>
                    <td data-label="Billed monthly" class="{{ $cell }}">{{ $bill::format($bill::monthly($forms)) }} a month</td>
                    <td data-label="Billed yearly" class="{{ $cell }}">{{ $bill::format($bill::yearly($forms)) }} a year</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
