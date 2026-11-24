@php($trial = config('billing.trial_days'))
@php($code = implode("\n", array_filter([
    '<form action="'.url('/f/YOUR_FORM_ID').'" method="POST">',
    $page['notice'] ? '  <p>'.$page['notice'].'</p>' : null,
    ...array_map(fn ($f) => match ($f[2]) {
        'textarea' => '  <label>'.$f[1]."\n    <textarea name=\"{$f[0]}\"".($f[3] ? ' required' : '').'></textarea></label>',
        default => '  <label>'.$f[1]."\n    <input type=\"{$f[2]}\" name=\"{$f[0]}\"".($f[3] ? ' required' : '').'></label>',
    }, $page['fields']),
    '  <input type="text" name="_gotcha" style="display:none" tabindex="-1" autocomplete="off">',
    '  <button type="submit">'.$page['submit'].'</button>',
    '</form>',
])))
<x-layouts.site :title="$page['title'].': FormHaven'" :description="$page['description']">
    <x-slot:jsonLd>
        @include('partials.schema-breadcrumbs', ['items' => [['Home', route('home')], ['Form templates', route('templates.index')], [$page['name'], route('templates.show', $slug)]]])
        @include('partials.schema-faq', ['questions' => $page['faq']])
    </x-slot:jsonLd>
    <nav aria-label="Breadcrumb" class="pt-8 text-sm text-zinc-600"><a href="{{ route('templates.index') }}" class="underline">Form templates</a> / {{ $page['name'] }}</nav>

    <x-site.hero
        :title="$page['h1']"
        :subtitle="$page['intro']"
        :cta="['href' => route('register'), 'label' => 'Get your form address', 'note' => $trial.' days free, no card. Paste the address where the code says YOUR_FORM_ID.']"
        code-label="{{ $slug }}.html"
        :code="$code" />

    <section class="-mx-6 grid gap-10 border-y border-zinc-200 bg-zinc-50 px-6 py-14 sm:-mx-8 sm:px-8 lg:grid-cols-2 xl:-mx-12 xl:px-12">
        <div class="flex flex-col gap-4">
            <h2 class="text-2xl font-semibold tracking-tight">What your visitors see</h2>
            <p class="max-w-xl text-zinc-700">Unstyled, so it takes your site's own CSS. Every field has a label and a name, which is all FormHaven needs.</p>
        </div>
        <div class="flex flex-col gap-3.5 rounded-xl border border-zinc-200 bg-white p-6" aria-label="Preview">
            @if ($page['notice'])
                <p class="text-sm text-zinc-700">{{ $page['notice'] }}</p>
            @endif
            @foreach ($page['fields'] as [$name, $label, $type, $required])
                <label class="flex flex-col gap-1.5 text-sm font-medium">{{ $label }}@if ($required) <span class="sr-only">(required)</span>@endif
                    @if ($type === 'textarea')
                        <textarea rows="2" disabled class="rounded-lg border border-zinc-300 bg-white px-3 py-2.5"></textarea>
                    @else
                        <input type="{{ $type }}" disabled class="rounded-lg border border-zinc-300 bg-white px-3 py-2.5">
                    @endif
                </label>
            @endforeach
            <span class="self-start rounded-lg bg-zinc-900 px-4 py-2.5 text-sm font-medium text-white">{{ $page['submit'] }}</span>
        </div>
    </section>

    <section class="flex flex-col gap-6 py-14">
        <h2 class="text-2xl font-semibold tracking-tight">Variations</h2>
        <div class="grid gap-5 md:grid-cols-3">
            @foreach ($page['variations'] as [$heading, $text])
                <div class="rounded-xl border border-zinc-200 p-6"><h3 class="font-semibold">{{ $heading }}</h3><p class="mt-1.5 text-zinc-700">{{ $text }}</p></div>
            @endforeach
        </div>
    </section>

    <section class="flex flex-wrap gap-10 border-t border-zinc-200 py-14">
        <h2 class="flex-[1_1_16rem] text-2xl font-semibold tracking-tight">Questions about this form</h2>
        <dl class="grid min-w-0 flex-[3_1_32rem] gap-6">
            @foreach ($page['faq'] as [$q, $a])
                <div><dt class="font-semibold">{{ $q }}</dt><dd class="mt-1 max-w-3xl text-zinc-700">{{ $a }}</dd></div>
            @endforeach
        </dl>
    </section>

    @if ($others)
        <section class="flex flex-col gap-5 border-t border-zinc-200 py-14">
            <h2 class="text-2xl font-semibold tracking-tight">More form templates</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($others as $otherSlug => $other)
                    <a href="{{ route('templates.show', $otherSlug) }}" class="flex flex-col gap-1 rounded-xl border border-zinc-200 p-5 hover:border-zinc-400"><span class="font-semibold">{{ $other['name'] }}</span><span class="text-sm text-zinc-600">{{ $other['card'] }}</span></a>
                @endforeach
            </div>
        </section>
    @endif

    <x-site.cta-band title="Get your form address in a minute" />
</x-layouts.site>
