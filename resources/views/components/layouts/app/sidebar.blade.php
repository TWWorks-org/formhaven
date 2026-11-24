@php
    $user = auth()->user();
    $plan = $user->plan();
    $subscribed = $user->subscribed('default');
    $used = $user->submissionsThisMonth();
    $sites = $user->formsBySite();
    $covered = $user->coveredFormIds();
    $formCount = $sites->sum(fn ($forms) => $forms->count());

    // On a form's page its site starts open. The route holds the id, not the model, on Volt routes.
    $routeForm = request()->route('form');
    $currentId = (int) (is_object($routeForm) ? $routeForm->getKey() : $routeForm);
    $currentSite = $currentId ? $sites->search(fn ($forms) => $forms->contains('id', $currentId)) : false;

    // A handful of forms shows open; past that, sites collapse so the list fits on one screen.
    $collapse = $formCount > 8;
    $topLink = fn (bool $on) => 'flex min-h-9 items-center rounded-lg px-3 font-medium '.($on ? 'bg-zinc-100 text-zinc-900 dark:bg-zinc-800 dark:text-white' : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100" x-data="{ sitesOpen: false }">
        <header class="sticky top-0 z-20 flex min-h-14 flex-wrap items-center gap-3 border-b border-zinc-200 bg-white px-4 py-2.5 sm:px-5 dark:border-zinc-800 dark:bg-zinc-900">
            <button type="button" class="grid size-9 place-items-center rounded-lg text-zinc-600 hover:bg-zinc-100 lg:hidden dark:text-zinc-300 dark:hover:bg-zinc-800" x-on:click="sitesOpen = ! sitesOpen" x-bind:aria-expanded="sitesOpen" aria-controls="sites-nav" aria-label="Show your sites and forms">
                <flux:icon.bars-2 class="size-5" />
            </button>
            <a href="{{ route('forms.index') }}" class="flex items-center gap-2.5 text-[15px] font-semibold" wire:navigate>
                <span class="grid size-7 place-items-center rounded-md bg-zinc-900 dark:bg-white"><x-app-logo-icon class="h-4 w-3 text-white dark:text-zinc-900" /></span>
                FormHaven
            </a>
            <nav aria-label="Main" class="ml-1 flex flex-wrap gap-0.5 text-sm max-sm:order-last max-sm:w-full">
                <a href="{{ route('forms.index') }}" class="{{ $topLink(request()->routeIs('forms.index')) }}" wire:navigate>Overview</a>
@if (\App\Support\Mode::hosted())
                <a href="{{ route('billing') }}" class="{{ $topLink(request()->routeIs('billing')) }}" wire:navigate>Billing</a>
@endif
                <a href="{{ route('api-keys') }}" class="{{ $topLink(request()->routeIs('api-keys')) }}" wire:navigate>API keys</a>
                @if ($user->isOwner())
                    <a href="{{ route('admin') }}" class="{{ $topLink(request()->routeIs('admin')) }}" wire:navigate>Admin</a>
                @endif
            </nav>

            <div class="flex-1"></div>

            @if ($plan && $plan['submissions'] !== null)
                <a href="{{ route('billing') }}" class="hidden min-h-8 items-center gap-2 rounded-full border border-zinc-200 px-3 text-xs font-medium tabular-nums text-zinc-700 hover:bg-zinc-100 md:inline-flex dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800" wire:navigate>
                    <span class="block h-1.5 w-10 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" aria-hidden="true"><span class="block h-full rounded-full bg-zinc-900 dark:bg-white" style="width: {{ min(100, round($used / max(1, $plan['submissions']) * 100)) }}%"></span></span>
                    {{ number_format($used) }} / {{ number_format($plan['submissions']) }} this month
                </a>
            @endif
            @if ($plan && ! $subscribed && $user->onGenericTrial())
                <span class="hidden h-7 items-center rounded-full bg-zinc-100 px-3 text-xs font-medium text-zinc-700 sm:inline-flex dark:bg-zinc-800 dark:text-zinc-300">Trial · {{ max(1, (int) ceil(now()->diffInDays($user->trial_ends_at))) }} days left</span>
            @endif
            @unless ($subscribed || $user->isOwner() || \App\Support\Mode::selfHosted())
                <flux:button size="sm" variant="primary" :href="route('billing')" wire:navigate class="max-sm:hidden">Subscribe</flux:button>
            @endunless

            <flux:dropdown position="bottom" align="end">
                <flux:profile :initials="$user->initials()" icon-trailing="chevron-down" />

                <flux:menu class="w-[240px]">
                    <div class="px-2 py-1.5 text-sm leading-tight">
                        <p class="truncate font-semibold">{{ $user->name }}</p>
                        <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $user->email }}</p>
                    </div>
                    <flux:menu.separator />
                    <flux:menu.item :href="route('settings.profile')" icon="cog" wire:navigate>Account settings</flux:menu.item>
@if (\App\Support\Mode::hosted())
                    <flux:menu.item :href="route('billing')" icon="credit-card" wire:navigate>Billing</flux:menu.item>
@endif
                    <flux:menu.item :href="route('llms')" icon="book-open-text">Guide for AI agents</flux:menu.item>
@if (\App\Support\Mode::hosted())
                    <flux:menu.item href="mailto:{{ config('legal.email') }}" icon="envelope">Email support</flux:menu.item>
@endif
                    <flux:menu.separator />
                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">Log out</flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </header>

        <div class="lg:flex lg:items-start">
            <aside
                id="sites-nav"
                class="flex flex-col gap-4 border-b border-zinc-200 bg-white px-3 py-4 lg:sticky lg:top-14 lg:h-[calc(100vh-3.5rem)] lg:w-[264px] lg:flex-none lg:border-r lg:border-b-0 dark:border-zinc-800 dark:bg-zinc-900"
                x-bind:class="{ 'max-lg:hidden': ! sitesOpen }"
                x-data="{
                    q: '',
                    open: @js($currentSite === false ? null : $currentSite),
                    names: @js($sites->flatMap(fn ($forms, $host) => $forms->pluck('name')->push($host === '' ? 'No website set' : $host))->map(fn ($n) => Str::lower($n))->values()),
                    get none() { return this.q !== '' && ! this.names.some(n => n.includes(this.q.toLowerCase())) },
                }"
            >
                <flux:button variant="primary" icon="plus" :href="route('forms.index', ['new' => 1])" wire:navigate>New form</flux:button>

                <div class="flex min-h-0 flex-1 flex-col">
                    <div class="flex items-baseline justify-between gap-2 px-2.5 pb-2">
                        <p class="text-[11px] font-semibold tracking-wider text-zinc-500 uppercase dark:text-zinc-400">Sites</p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $formCount }} {{ Str::plural('form', $formCount) }} on {{ $sites->count() }} {{ Str::plural('site', $sites->count()) }}</p>
                    </div>

                    @if ($formCount === 0)
                        <p class="px-2.5 text-sm text-zinc-500 dark:text-zinc-400">Your forms show up here, grouped by the site they are on.</p>
                    @else
                        <label class="mb-1 flex min-h-9 items-center gap-2 rounded-lg border border-zinc-300 bg-white px-2.5 dark:border-zinc-700 dark:bg-zinc-900">
                            <flux:icon.magnifying-glass class="size-4 text-zinc-500" />
                            <input type="search" x-model="q" placeholder="Find a form or site" aria-label="Find a form or site" class="w-full bg-transparent text-sm outline-none placeholder:text-zinc-500">
                        </label>

                        <nav aria-label="Your forms" class="-mx-1 flex min-h-0 flex-1 flex-col gap-px overflow-y-auto px-1">
                            @foreach ($sites as $host => $forms)
                                @php
                                    $siteLabel = $host === '' ? 'No website set' : $host;
                                    $siteKey = (string) $host;
                                    $haystack = Str::lower($siteLabel);
                                @endphp
                                <div x-show="! q || @js($haystack).includes(q.toLowerCase()) || @js($forms->map(fn ($f) => Str::lower($f->name))->values()).some(n => n.includes(q.toLowerCase()))">
                                    @if ($collapse)
                                        <button type="button" class="flex min-h-8 w-full items-center gap-2 rounded-lg px-2.5 text-left text-sm font-medium text-zinc-700 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-white" x-on:click="open = open === @js($siteKey) ? null : @js($siteKey)" x-bind:aria-expanded="q !== '' || open === @js($siteKey)">
                                            <flux:icon.chevron-right class="size-3.5 flex-none text-zinc-400 transition-transform" x-bind:class="(q !== '' || open === {{ Js::from($siteKey) }}) && 'rotate-90 text-zinc-900 dark:text-white'" />
                                            <span class="min-w-0 flex-1 truncate">{{ $siteLabel }}</span>
                                            <span class="text-xs text-zinc-500 tabular-nums">{{ $forms->count() }}</span>
                                        </button>
                                    @else
                                        <p @class(['truncate px-2.5 pb-1 text-xs text-zinc-500 dark:text-zinc-400', 'pt-3' => ! $loop->first])>{{ $siteLabel }}</p>
                                    @endif

                                    <div @class(['ml-[18px] flex flex-col gap-px border-l border-zinc-200 pl-2 dark:border-zinc-700' => $collapse, 'flex flex-col gap-px' => ! $collapse])
                                        @if ($collapse) x-show="q !== '' || open === @js($siteKey)" @endif>
                                        @foreach ($forms as $form)
                                            @php $status = $form->dashboardStatus($covered); @endphp
                                            <a href="{{ route('forms.show', $form) }}" wire:navigate
                                                x-show="! q || @js($haystack).includes(q.toLowerCase()) || @js(Str::lower($form->name)).includes(q.toLowerCase())"
                                                @class([
                                                    'flex min-h-8 items-center justify-between gap-2 rounded-lg px-2.5 text-sm',
                                                    'bg-zinc-100 font-medium text-zinc-900 dark:bg-zinc-800 dark:text-white' => $form->id === $currentId,
                                                    'text-zinc-700 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800 dark:hover:text-white' => $form->id !== $currentId,
                                                ])
                                                @if ($form->id === $currentId) aria-current="page" @endif>
                                                <span class="flex min-w-0 items-center gap-2">
                                                    <span @class(['size-[7px] flex-none rounded-full', 'bg-green-600' => $status === 'active', 'bg-zinc-300 dark:bg-zinc-600' => $status === 'waiting', 'bg-red-600' => $status === 'paused']) aria-hidden="true"></span>
                                                    <span class="truncate">{{ $form->name }}</span>
                                                </span>
                                                <span class="text-xs text-zinc-500 tabular-nums" title="Submissions this month">{{ number_format($form->month_count) }}</span>
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                            <p class="px-2.5 py-2 text-sm text-zinc-500" x-show="none" x-cloak>Nothing matches "<span x-text="q"></span>".</p>
                        </nav>
                    @endif
                </div>
            </aside>

            <main class="min-w-0 flex-1 px-4 py-6 sm:px-8 sm:py-7 xl:px-12">
                {{ $slot }}
            </main>
        </div>

        @fluxScripts
    </body>
</html>
