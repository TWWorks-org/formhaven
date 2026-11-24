@props(['status'])

@php
    [$label, $class] = match ($status) {
        'paused' => ['Paused', 'bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-200'],
        'attention' => ['Needs attention', 'bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-100'],
        'quiet' => ['Quiet', 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'],
        'waiting' => ['Waiting for first message', 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300'],
        default => ['Active', 'bg-green-50 text-green-800 dark:bg-green-950 dark:text-green-200'],
    };
@endphp

<span {{ $attributes->class(['inline-flex h-[22px] items-center whitespace-nowrap rounded-full px-2 text-xs font-medium', $class]) }}>{{ $label }}</span>
