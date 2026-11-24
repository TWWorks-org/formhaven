@props(['tone' => 'white'])
{{-- A full-width section of a public page: it pulls out to the page frame's guide lines (the layout's
     main padding) and starts with a hairline rule. tone: white, tint (zinc-50) or dark. --}}
<section {{ $attributes->class([
    '-mx-6 border-t px-6 sm:-mx-8 sm:px-8 xl:-mx-12 xl:px-12',
    'border-zinc-200' => $tone === 'white',
    'border-zinc-200 bg-zinc-50' => $tone === 'tint',
    'border-zinc-900 bg-zinc-900 text-zinc-100' => $tone === 'dark',
]) }}>
    {{ $slot }}
</section>
