@props(['dot' => false, 'dark' => false])
{{-- The small uppercase monospace label above a heading. dot: the green status dot; dark: for the dark bands. --}}
<p {{ $attributes->class(['flex items-center gap-2.5 font-mono text-xs tracking-[0.12em] uppercase', 'text-zinc-300' => $dark, 'text-zinc-600' => ! $dark]) }}>
    @if ($dot)<span aria-hidden="true" class="inline-block size-2 flex-none rounded-full bg-green-700"></span>@endif
    {{ $slot }}
</p>
