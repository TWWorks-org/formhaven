@php($perForm = config('billing.per_form'))
@php($trial = config('billing.trial_days'))
@php($bill = \App\Support\Bill::class)
{{-- ":price:N": the monthly bill for an account with the minimum forms that receives N submissions a month, extra thousands included. --}}
@php($price = fn (int $n) => $bill::monthly($perForm['min_forms']) + max(0, (int) ceil($n / $perForm['submissions']) - $perForm['min_forms']) * $perForm['extra_eur'])
{{-- ":for:N": the cheapest monthly bill for N submissions a month, the same sum as ":price:N". An extra 1,000 never costs more
     than a form (config/billing.php), so the cheapest way is the minimum forms with the rest as extra thousands. When the
     minimum's own submissions cover N, the cell also gives the yearly bill; extra thousands are billed per month, so past it
     the cell is monthly only and says how much is included and what the rest costs. --}}
@php($fill = function (string $cell) use ($perForm, $trial, $bill, $price) {
    if ($cell === ':trial') {
        return $trial.'-day trial, no card';
    }
    if ($cell === ':min' || str_starts_with($cell, ':for:')) {
        $n = (int) substr($cell, 5);
        $min = $perForm['min_forms'];
        $included = $min * $perForm['submissions'];
        $extra = max(0, (int) ceil($n / $perForm['submissions']) - $min);

        if ($extra === 0) {
            return $bill::format($bill::monthly($min)).' a month, or '.$bill::format($bill::yearly($min)).' a year ('.number_format($included).' submissions a month, on up to '.$min.' forms)';
        }

        return $bill::format($price($n)).' a month on up to '.$min.' forms ('.number_format($included).' submissions included, the next '.number_format($extra * $perForm['submissions']).' at '.$bill::format($perForm['extra_eur']).' per 1,000)';
    }

    return $cell;
})
{{-- ":price:N" inside running text (see above). --}}
@php($inline = fn (string $text) => preg_replace_callback('/:price:(\d+)/', fn ($m) => $bill::format($price((int) $m[1])).' a month', $text))
<x-layouts.site :title="$page['title'].': FormHaven'" :description="$page['description']">
    <nav aria-label="Breadcrumb" class="pt-8 text-sm text-zinc-600">Compare / {{ $page['name'] }}</nav>

    <x-site.hero
        :title="$page['h1']"
        :subtitle="$page['intro']"
        :cta="['href' => route('register'), 'label' => 'Start your '.$trial.'-day trial', 'note' => 'No card needed.']"
        :secondary="['href' => '#switch', 'label' => 'Switch in two minutes']" />

    <section class="pb-14">
        <div class="overflow-x-auto rounded-xl border border-zinc-200">
            <table class="w-full min-w-[40rem] text-left text-sm">
                <thead class="bg-zinc-100"><tr><th class="w-1/3 px-4 py-3"><span class="sr-only">Compared</span></th><th class="px-4 py-3 font-semibold">FormHaven</th><th class="px-4 py-3 font-semibold">{{ $page['name'] }}</th></tr></thead>
                <tbody>
                    @foreach ($page['rows'] as [$label, $ours, $theirs])
                        <tr class="border-t border-zinc-200">
                            <td class="px-4 py-3 font-medium">{{ $label }}</td>
                            <td class="px-4 py-3">{{ $fill($ours) }}</td>
                            <td class="px-4 py-3" data-rival>{{ $theirs }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-sm text-zinc-600">{{ $page['figures_note'] ?? $page['name'].' figures are monthly billing,' }} read on {{ $page['checked'] }} from
            @foreach ($page['sources'] as [$sourceLabel, $sourceUrl])<a href="{{ $sourceUrl }}" target="_blank" rel="noopener nofollow" class="underline">{{ $sourceLabel }}</a>{{ $loop->last ? '.' : ' and ' }}@endforeach
            Spot something out of date? Email <a href="mailto:{{ config('legal.support_email') }}" class="underline">{{ config('legal.support_email') }}</a>.</p>
    </section>

    <section class="-mx-6 grid gap-6 border-y border-zinc-200 bg-zinc-50 px-6 py-14 sm:-mx-8 sm:px-8 md:grid-cols-2 xl:-mx-12 xl:px-12">
        <div class="rounded-2xl border-2 border-zinc-900 bg-white p-7">
            <h2 class="text-xl font-semibold">Pick FormHaven if</h2>
            <ul class="mt-3 list-disc space-y-2 pl-5 text-zinc-800">
                @foreach ($page['pick_us'] as $reason)<li>{{ $inline($reason) }}</li>@endforeach
            </ul>
        </div>
        {{-- data-rival: the rival's own figures, which CopyConsistencyTest leaves out of the check on our prices. --}}
        <div class="rounded-2xl border border-zinc-200 bg-white p-7" data-rival>
            <h2 class="text-xl font-semibold">Stay with {{ $page['name'] }} if</h2>
            <ul class="mt-3 list-disc space-y-2 pl-5 text-zinc-800">
                @foreach ($page['pick_them'] as $reason)<li>{{ $reason }}</li>@endforeach
            </ul>
        </div>
    </section>

    <section id="switch" class="flex flex-wrap gap-8 py-14">
        <div class="flex min-w-0 flex-[1_1_22rem] flex-col gap-3">
            <h2 class="text-2xl font-semibold tracking-tight">Switch in two minutes</h2>
            <p class="max-w-xl text-zinc-700">{{ $page['switch']['note'] }}</p>
        </div>
        {{-- A diff per rival: the lines their HTML has, the lines ours needs. {endpoint} is this form's address. --}}
        <pre class="min-w-0 flex-[1_1_30rem] overflow-x-auto rounded-xl bg-zinc-900 p-5 text-sm leading-relaxed text-zinc-200"><code>@foreach ($page['switch']['remove'] as $line)<span class="text-zinc-400">- {{ $line }}</span>
@endforeach
@foreach ($page['switch']['add'] as $line)<span class="text-white">+ {{ str_replace('{endpoint}', url('/f/YOUR_FORM_ID'), $line) }}</span>
@endforeach
@foreach ($page['switch']['keep'] ?? [] as $line)  {{ $line }}   <span class="text-zinc-400">(unchanged)</span>
@endforeach</code></pre>
    </section>

    <x-site.cta-band title="Move one client form today" />
</x-layouts.site>
