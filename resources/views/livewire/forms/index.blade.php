<?php

use App\Actions\CreateForm;
use App\Models\Submission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new class extends Component {
    public string $name = '';

    public string $site_url = '';

    public bool $creating = false;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $status = 'all';

    public function mount(): void
    {
        // The sidebar's "New form" lands here with ?new=1 and opens the dialog.
        $this->creating = request()->boolean('new');
    }

    public function createForm(): void
    {
        $form = app(CreateForm::class)->handle(Auth::user(), ['name' => $this->name, 'site_url' => $this->site_url ?: null]);

        $this->redirectRoute('forms.show', $form, navigate: true);
    }

    public function with(): array
    {
        $user = Auth::user();
        $plan = $user->plan();
        $covered = $user->coveredFormIds();
        $sites = $user->formsBySite();
        $all = $sites->flatten(1);
        $term = Str::lower(trim($this->search));
        $formIds = $user->forms()->select('id');

        $shown = $sites
            ->map(fn ($forms, $host) => $forms->filter(fn ($form) => ($this->status === 'all' || $form->dashboardStatus($covered) === $this->status)
                && ($term === '' || str_contains(Str::lower($host.' '.$form->name), $term))))
            ->filter(fn ($forms) => $forms->isNotEmpty());

        return [
            'plan' => $plan,
            'covered' => $covered,
            'sites' => $shown,
            'formCount' => $all->count(),
            'siteCount' => $sites->count(),
            'statusCounts' => $all->countBy(fn ($form) => $form->dashboardStatus($covered)),
            'submissionsUsed' => $user->submissionsThisMonth(),
            'spamThisMonth' => Submission::whereIn('form_id', $formIds)->where('spam', true)->where('created_at', '>=', now()->startOfMonth())->count(),
            'latest' => Submission::with('form')->whereIn('form_id', $formIds)->where('spam', false)->latest()->limit(5)->get(),
        ];
    }
}; ?>

<div class="flex w-full min-w-0 flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">Overview</flux:heading>
            <flux:subheading>Each form gets its own address. Point your HTML form at it and submissions land in your inbox.</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="$set('creating', true)">New form</flux:button>
    </div>

    @if (session('status'))
        <p class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-900 dark:border-green-800 dark:bg-green-950 dark:text-green-100" role="status">{{ session('status') }}</p>
    @endif

    <x-plan-status />

    <div class="grid gap-4 sm:grid-cols-[repeat(auto-fit,minmax(220px,1fr))]">
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Submissions this month</p>
            <p class="mt-1.5 text-3xl font-semibold tracking-tight tabular-nums">{{ number_format($submissionsUsed) }}@if ($plan && $plan['submissions'] !== null) <span class="text-sm font-medium tracking-normal text-zinc-500">of {{ number_format($plan['submissions']) }}</span>@endif</p>
            @if ($plan && $plan['submissions'] !== null)
                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800" aria-hidden="true"><div class="h-full rounded-full bg-zinc-900 dark:bg-white" style="width: {{ min(100, round($submissionsUsed / max(1, $plan['submissions']) * 100)) }}%"></div></div>
            @endif
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Spam caught this month</p>
            <p class="mt-1.5 text-3xl font-semibold tracking-tight tabular-nums">{{ number_format($spamThisMonth) }}</p>
            <p class="mt-1 text-sm text-zinc-500">Kept out of your inbox and not counted.</p>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <p class="text-sm font-medium text-zinc-600 dark:text-zinc-400">Forms</p>
            <p class="mt-1.5 text-3xl font-semibold tracking-tight tabular-nums">{{ $formCount }}@if ($plan) <span class="text-sm font-medium tracking-normal text-zinc-500">{{ $plan['forms'] ? 'of '.$plan['forms'] : 'no limit' }}</span>@endif</p>
            @if ($plan && $plan['forms'])
                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800" aria-hidden="true"><div class="h-full rounded-full bg-zinc-900 dark:bg-white" style="width: {{ min(100, round($formCount / $plan['forms'] * 100)) }}%"></div></div>
            @endif
        </div>
    </div>

    @if ($formCount === 0)
        <section class="rounded-xl border border-dashed border-zinc-300 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="lg">Create your first form</flux:heading>
            <p class="mx-auto mt-2 max-w-xl text-sm text-zinc-600 dark:text-zinc-400">No forms yet. Create one to get your form address, paste it into your site's form, and every submission lands in your inbox.</p>
            <flux:button class="mt-5" variant="primary" icon="plus" wire:click="$set('creating', true)">New form</flux:button>
        </section>
    @else
        <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-wrap items-center gap-3 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <div class="w-full sm:w-72">
                    <flux:input wire:model.live.debounce.300ms="search" type="search" size="sm" icon="magnifying-glass" placeholder="Search {{ $formCount }} {{ Str::plural('form', $formCount) }} and {{ $siteCount }} {{ Str::plural('site', $siteCount) }}" aria-label="Search forms and sites" />
                </div>
                <div class="inline-flex flex-wrap rounded-lg bg-zinc-100 p-[3px] dark:bg-zinc-800" role="group" aria-label="Status">
                    @foreach (['all' => 'All', 'attention' => 'Needs attention', 'quiet' => 'Quiet', 'active' => 'Active', 'waiting' => 'Waiting', 'paused' => 'Paused'] as $key => $label)
                        @php $count = $key === 'all' ? $formCount : ($statusCounts[$key] ?? 0); @endphp
                        @if ($key === 'all' || $count > 0)
                            <button type="button" wire:click="$set('status', '{{ $key }}')" aria-pressed="{{ $status === $key ? 'true' : 'false' }}" @class(['min-h-8 rounded-md px-3 text-sm font-medium', 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white' => $status === $key, 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white' => $status !== $key])>{{ $label }} <span class="text-zinc-500">{{ $count }}</span></button>
                        @endif
                    @endforeach
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-zinc-50 text-xs text-zinc-600 dark:bg-zinc-800/50 dark:text-zinc-400">
                        <tr>
                            <th class="px-4 py-2.5 font-semibold">Site and form</th>
                            <th class="px-4 py-2.5 font-semibold">Address</th>
                            <th class="px-4 py-2.5 font-semibold">Last submission</th>
                            <th class="px-4 py-2.5 text-right font-semibold">This month</th>
                            <th class="px-4 py-2.5 font-semibold">Status</th>
                        </tr>
                    </thead>
                    @forelse ($sites as $host => $forms)
                        <tbody wire:key="site-{{ $host }}" class="border-t border-zinc-200 dark:border-zinc-700">
                            @php $lastAt = $forms->max('last_submission_at'); @endphp
                            <tr class="bg-white dark:bg-zinc-900">
                                <td class="px-4 py-3 font-semibold whitespace-nowrap">{{ $host === '' ? 'No website set' : $host }} <span class="font-normal text-zinc-500">· {{ $forms->count() }} {{ Str::plural('form', $forms->count()) }}</span></td>
                                <td></td>
                                <td class="px-4 py-3 whitespace-nowrap text-zinc-600 dark:text-zinc-400">{{ $lastAt ? Carbon::parse($lastAt)->diffForHumans() : '' }}</td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ number_format($forms->sum('month_count')) }}</td>
                                <td></td>
                            </tr>
                            @foreach ($forms as $form)
                                <tr wire:key="form-{{ $form->id }}" class="border-t border-zinc-100 bg-zinc-50/60 dark:border-zinc-800 dark:bg-zinc-950/40">
                                    <td class="py-3 pr-4 pl-9 whitespace-nowrap"><a href="{{ route('forms.show', $form) }}" class="font-medium hover:underline" wire:navigate>{{ $form->name }}</a></td>
                                    <td class="px-4 py-3 font-mono text-xs whitespace-nowrap text-zinc-600 dark:text-zinc-400">/f/{{ $form->public_id }}</td>
                                    <td class="px-4 py-3 whitespace-nowrap text-zinc-600 dark:text-zinc-400">{{ $form->last_submission_at ? Carbon::parse($form->last_submission_at)->diffForHumans() : 'None yet' }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ number_format($form->month_count) }}</td>
                                    <td class="px-4 py-3"><x-app.status-badge :status="$form->dashboardStatus($covered)" /></td>
                                </tr>
                            @endforeach
                        </tbody>
                    @empty
                        <tbody>
                            <tr><td colspan="5" class="px-4 py-8 text-center text-zinc-500">Nothing matches{{ $search !== '' ? ' "'.$search.'"' : '' }}{{ $status !== 'all' ? ' with this status' : '' }}.</td></tr>
                        </tbody>
                    @endforelse
                </table>
            </div>
        </section>

        <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <flux:heading size="md" level="2">Latest submissions</flux:heading>
                <span class="text-sm text-zinc-500">From every form</span>
            </div>
            @forelse ($latest as $submission)
                @php $answers = collect($submission->data)->map(fn ($a) => is_array($a) ? implode(', ', $a) : (string) $a)->filter(); @endphp
                <a href="{{ route('forms.show', $submission->form) }}" wire:navigate wire:key="latest-{{ $submission->id }}" class="flex flex-wrap items-baseline gap-x-6 gap-y-1 border-t border-zinc-100 px-4 py-3 text-sm first:border-t-0 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800/50">
                    <span class="w-48 flex-none truncate font-medium">{{ $submission->isForgotten() ? 'Emailed to you' : ($answers->get('name') ?? $submission->replyToAddress() ?? $answers->first()) }}</span>
                    <span class="min-w-0 flex-1 truncate text-zinc-600 dark:text-zinc-400">{{ $submission->isForgotten() ? 'The answers were not kept.' : ($answers->get('message') ?? $answers->except(['name', 'email'])->implode(' · ')) }}</span>
                    <span class="truncate text-zinc-500">{{ $submission->form->name }}{{ $submission->form->siteHost() ? ' · '.$submission->form->siteHost() : '' }}</span>
                    <span class="w-28 flex-none text-right whitespace-nowrap text-zinc-500">{{ $submission->created_at->diffForHumans() }}</span>
                </a>
            @empty
                <p class="px-4 py-6 text-sm text-zinc-500">No submissions yet. Open a form and press Send a test submission to see one arrive.</p>
            @endforelse
        </section>
    @endif

    <flux:modal wire:model.self="creating" class="w-full max-w-lg">
        <form wire:submit="createForm" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">New form</flux:heading>
                <flux:subheading>You get its address right away. Add the site so it is grouped with that client's other forms.</flux:subheading>
            </div>
            <flux:input wire:model="name" label="Form name" placeholder="Contact form" required />
            <flux:input wire:model="site_url" type="url" label="Website (optional)" placeholder="https://example.nl" />
            <div class="flex justify-end gap-2">
                <flux:button type="button" variant="ghost" wire:click="$set('creating', false)">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Create form</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
