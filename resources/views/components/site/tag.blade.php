{{-- Marks a mock: "Example data" or "Illustrative". Every made-up card or table on a public page carries one. --}}
<span {{ $attributes->class(['inline-block flex-none rounded border border-zinc-300 bg-white px-2.5 py-1 font-mono text-[0.6875rem] tracking-[0.08em] text-zinc-600 uppercase']) }}>{{ $slot }}</span>
