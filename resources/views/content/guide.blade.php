{{-- One PHP block on purpose: Blade folds an inline php directive above a php block into that block.
     $t is the template's own words per language: a guide sets 'lang' => 'de', every other guide is English.
     $alternates (hreflang) is this page and its counterpart ('alternates' => [lang => slug]); English is the default. --}}
@php
    $trial = config('billing.trial_days');
    $fill = fn (string $text) => strtr($text, ['{endpoint}' => url('/f/YOUR_FORM_ID'), '{botcheck}' => \App\Models\Form::botCheckScript(), '{api}' => url('/api/v1/forms'), '{start}' => url('/api/v1/start'), '{mcp}' => url('/mcp'), '{llms}' => url('/llms.txt')]);
    $heroCode = $fill(implode(PHP_EOL, $page['code']));
    $lang = $page['lang'] ?? 'en';
    $t = [
        'en' => [
            'cta' => 'Get your form address',
            'cta_note' => $trial.' days free, no card. Paste the address where the code says YOUR_FORM_ID.',
            'steps' => 'Set it up',
            'faq' => 'Questions about this setup',
            'more' => 'More to read',
            'template' => ['Contact form template', 'The same form in plain HTML'],
            'pricing' => ['Pricing', \App\Support\Bill::format(config('billing.per_form.monthly_eur')).' per form a month, '.\App\Support\Bill::format(config('billing.per_form.lower_monthly_eur')).' from the '.\App\Support\Bill::firstLowerForm()],
            'band' => ['title' => 'Get your form address in a minute', 'text' => null, 'button' => null],
            'copy' => ['label' => 'Copy', 'copied' => 'Copied', 'failed' => 'Select and copy'],
        ],
        'de' => [
            'cta' => 'Formular-Adresse holen',
            'cta_note' => $trial.' Tage kostenlos testen, ohne Karte. Setz die Adresse dort ein, wo im Code YOUR_FORM_ID steht.',
            'steps' => 'So richtest du es ein',
            'faq' => 'Fragen zu diesem Setup',
            'more' => 'Weiterlesen (auf Englisch)',
            'template' => ['Contact form template', 'Dasselbe Formular als reines HTML'],
            'pricing' => ['Pricing', \App\Support\Bill::formatDe(config('billing.per_form.monthly_eur')).' pro Formular im Monat, ab dem '.(config('billing.per_form.full_price_forms') + 1).'. Formular '.\App\Support\Bill::formatDe(config('billing.per_form.lower_monthly_eur'))],
            'band' => [
                'title' => 'Deine Formular-Adresse in einer Minute',
                'text' => $trial.' Tage kostenlos, ohne Karte. Danach '.\App\Support\Bill::formatDe(config('billing.per_form.monthly_eur')).' pro Formular im Monat, ab dem '.(config('billing.per_form.full_price_forms') + 1).'. Formular '.\App\Support\Bill::formatDe(config('billing.per_form.lower_monthly_eur')).'.',
                'button' => $trial.' Tage kostenlos testen',
            ],
            'copy' => ['label' => 'Kopieren', 'copied' => 'Kopiert', 'failed' => 'Markieren und kopieren'],
        ],
    ][$lang];
    $alternates = isset($page['alternates']) ? [$lang => route('guides.show', $slug)] + array_map(fn ($other) => route('guides.show', $other), $page['alternates']) : [];
    $alternates += isset($alternates['en']) ? ['x-default' => $alternates['en']] : [];
@endphp
<x-layouts.site :title="$page['title'].': FormHaven'" :description="$page['description']" :lang="$lang" :alternates="$alternates">
    {{-- No dates: the guide content has none, so the Article claims none. The FAQ is the same array the page shows. --}}
    <x-slot:jsonLd>
        @include('partials.schema-article', ['headline' => $page['h1'], 'description' => $page['description'], 'url' => route('guides.show', $slug)])
        @include('partials.schema-breadcrumbs', ['items' => [['Home', route('home')], ['Guides', route('guides.index')], [$page['name'], route('guides.show', $slug)]]])
        @include('partials.schema-faq', ['questions' => $page['faq']])
    </x-slot:jsonLd>
    <nav aria-label="Breadcrumb" class="pt-8 text-sm text-zinc-600"><a href="{{ route('guides.index') }}" class="hover:underline">Guides</a> / {{ $page['name'] }}</nav>

    <x-site.hero
        :title="$page['h1']"
        :subtitle="$page['intro']"
        :cta="['href' => route('register'), 'label' => $t['cta'], 'note' => $page['cta_note'] ?? $t['cta_note']]"
        :code-label="$page['file']"
        :code="$heroCode"
        :copy="$t['copy']" />

    <section class="-mx-6 flex flex-col gap-6 border-y border-zinc-200 bg-zinc-50 px-6 py-14 sm:-mx-8 sm:px-8 xl:-mx-12 xl:px-12">
        <h2 class="text-2xl font-semibold tracking-tight">{{ $page['steps_heading'] ?? $t['steps'] }}</h2>
        <ol class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($page['steps'] as $i => [$heading, $text])
                <li class="rounded-xl border border-zinc-200 bg-white p-6">
                    <p class="font-mono text-sm text-zinc-500">{{ $i + 1 }}</p>
                    <h3 class="mt-1 font-semibold">{{ $heading }}</h3>
                    <p class="mt-1.5 text-zinc-700 [overflow-wrap:anywhere]">{{ $fill($text) }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    @foreach ($page['variants'] as $variant)
        <section class="flex flex-wrap gap-10 py-14 {{ $loop->first ? '' : 'border-t border-zinc-200' }}">
            <div class="flex min-w-0 flex-[1_1_20rem] flex-col gap-3">
                <h2 class="text-2xl font-semibold tracking-tight">{{ $variant['heading'] }}</h2>
                <p class="max-w-xl text-zinc-700">{{ $variant['text'] }}</p>
            </div>
            <div class="min-w-0 flex-[2_1_32rem] overflow-hidden rounded-xl bg-zinc-900 text-zinc-100">
                <div class="flex items-center justify-between border-b border-zinc-700 px-4 py-3 font-mono text-xs text-zinc-400">
                    <span>{{ $variant['file'] ?? $page['file'] }}</span>
                    <button type="button" data-copy data-copied="{{ $t['copy']['copied'] }}" data-copy-failed="{{ $t['copy']['failed'] }}" class="rounded-md border border-zinc-600 bg-zinc-800 px-2.5 py-1 text-zinc-200">{{ $t['copy']['label'] }}</button>
                </div>
                <pre class="overflow-x-auto p-5 text-sm leading-relaxed"><code data-copy-source>{{ $fill(implode("\n", $variant['code'])) }}</code></pre>
            </div>
        </section>
    @endforeach

    <section class="flex flex-wrap gap-10 border-t border-zinc-200 py-14">
        <h2 class="flex-[1_1_16rem] text-2xl font-semibold tracking-tight">{{ $t['faq'] }}</h2>
        <dl class="grid min-w-0 flex-[3_1_32rem] gap-6">
            @foreach ($page['faq'] as [$q, $a])
                <div><dt class="font-semibold">{{ $q }}</dt><dd class="mt-1 max-w-3xl text-zinc-700">{{ $a }}</dd></div>
            @endforeach
        </dl>
    </section>

    <section class="flex flex-col gap-5 border-t border-zinc-200 py-14">
        <h2 class="text-2xl font-semibold tracking-tight">{{ $t['more'] }}</h2>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {{-- A guide that feeds a comparison or template names it first: that link is the page's job. --}}
            @foreach ($page['related'] ?? [] as [$relatedRoute, $relatedSlug, $relatedName, $relatedCard])
                <a href="{{ route($relatedRoute, $relatedSlug) }}" class="flex flex-col gap-1 rounded-xl border-2 border-zinc-900 p-5"><span class="font-semibold">{{ $relatedName }}</span><span class="text-sm text-zinc-600">{{ $relatedCard }}</span></a>
            @endforeach
            @foreach (array_diff_key($others, array_flip(array_column($page['related'] ?? [], 1))) as $otherSlug => $other)
                <a href="{{ route('guides.show', $otherSlug) }}" class="flex flex-col gap-1 rounded-xl border border-zinc-200 p-5 hover:border-zinc-400"><span class="font-semibold">{{ $other['name'] }}</span><span class="text-sm text-zinc-600">{{ $other['card'] }}</span></a>
            @endforeach
            <a href="{{ route('templates.show', 'contact-form') }}" class="flex flex-col gap-1 rounded-xl border border-zinc-200 p-5 hover:border-zinc-400"><span class="font-semibold">{{ $t['template'][0] }}</span><span class="text-sm text-zinc-600">{{ $t['template'][1] }}</span></a>
            <a href="{{ route('pricing') }}" class="flex flex-col gap-1 rounded-xl border border-zinc-200 p-5 hover:border-zinc-400"><span class="font-semibold">{{ $t['pricing'][0] }}</span><span class="text-sm text-zinc-600">{{ $t['pricing'][1] }}</span></a>
        </div>
    </section>

    <x-site.cta-band :title="$t['band']['title']" :text="$t['band']['text']" :button="$t['band']['button']" />
</x-layouts.site>
