@props(['title', 'subtitle', 'eyebrow' => null, 'cta' => null, 'secondary' => null, 'aside' => null, 'code' => null, 'codeLabel' => null, 'copy' => ['label' => 'Copy', 'copied' => 'Copied', 'failed' => 'Select and copy']])
{{-- Shared hero for every public page: pages pass copy, one action, an optional second action and an optional code panel; the layout stays here. --}}
<section class="flex flex-wrap items-center gap-x-12 gap-y-10 pt-12 pb-14 sm:pt-[4.5rem]">
    <div class="flex min-w-0 flex-[1_1_32rem] flex-col gap-6">
        @if ($eyebrow)
            <x-site.eyebrow dot>{{ $eyebrow }}</x-site.eyebrow>
        @endif
        <h1 class="max-w-4xl text-4xl leading-[1.06] font-semibold tracking-[-0.03em] text-balance sm:text-5xl xl:text-[3.5rem] xl:leading-[1.04]">{{ $title }}</h1>
        <p class="max-w-2xl text-lg text-zinc-700 sm:text-[1.1875rem]">{{ $subtitle }}</p>
        @if ($cta)
            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ $cta['href'] }}" class="rounded bg-zinc-900 px-5.5 py-3.5 font-medium text-white hover:bg-zinc-700">{{ $cta['label'] }}</a>
                @if ($secondary)
                    <a href="{{ $secondary['href'] }}" class="rounded border border-zinc-900 px-5.5 py-[0.8125rem] font-medium hover:bg-zinc-100">{{ $secondary['label'] }}</a>
                @endif
            </div>
            @isset($cta['note'])
                <p class="text-sm text-zinc-600">{{ $cta['note'] }}</p>
            @endisset
        @endif
        @if ($aside)
            <p class="font-mono text-sm text-zinc-700">{{ $aside }}</p>
        @endif
    </div>
    @if ($code)
        <div class="min-w-0 flex-[1_1_28rem] self-center overflow-hidden rounded border border-zinc-900 bg-zinc-900 text-zinc-100">
            <div class="flex items-center justify-between gap-3 border-b border-zinc-700 px-4 py-3 font-mono text-xs tracking-[0.08em] text-zinc-300 uppercase">
                <span class="min-w-0 truncate">{{ $codeLabel ?? 'index.html' }}</span>
                <button type="button" data-copy data-copied="{{ $copy['copied'] }}" data-copy-failed="{{ $copy['failed'] }}" class="rounded border border-zinc-600 bg-zinc-800 px-3.5 py-2 tracking-[0.08em] text-zinc-100 uppercase hover:bg-zinc-700">{{ $copy['label'] }}</button>
            </div>
            <pre class="overflow-x-auto px-5 pt-5 pb-6 font-mono text-sm leading-relaxed max-sm:whitespace-pre-wrap max-sm:[overflow-wrap:anywhere]"><code data-copy-source>{{ $code }}</code></pre>
        </div>
    @endif
</section>
