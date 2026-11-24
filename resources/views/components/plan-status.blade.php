@php
    $user = auth()->user();
    $plan = $user->plan();
    $pausedForms = $plan ? $user->pausedFormsCount() : 0;
@endphp

@if ($plan === null)
    <div class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-100">
        Your trial has ended, so your forms are paused and visitors see a "form paused" message.
        <a href="{{ route('billing') }}" class="font-medium underline" wire:navigate>Subscribe</a> to switch them back on.
    </div>
@else
    @if ($user->subscription('default')?->pastDue())
        <div class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-100">
            Your last payment failed. Your forms keep working while we try the card again over the next days.
            <a href="{{ route('billing') }}" class="font-medium underline" wire:navigate>Update your card</a> so they stay on.
        </div>
    @endif

    @if ($pausedForms > 0)
        <div class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-900 dark:border-red-800 dark:bg-red-950 dark:text-red-100">
            Your trial covers {{ $plan['forms'] }} forms, so your {{ $pausedForms === 1 ? 'newest form is' : $pausedForms.' newest forms are' }} paused and visitors see a "form paused" message.
            Delete forms you no longer need, or <a href="{{ route('billing') }}" class="font-medium underline" wire:navigate>subscribe</a> to switch them back on.
        </div>
    @endif

    @if ($user->emailsPaused())
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
            You are past this month's {{ number_format($plan['submissions']) }} submissions and the {{ number_format($user->emailCutoff() - $plan['submissions']) }} extra we email on top. New ones are still saved here, but no longer emailed.
            <a href="{{ route('billing') }}" class="font-medium underline" wire:navigate>Subscribe</a> to get emails again, or wait until the 1st.
        </div>
    @elseif ($user->overSubmissionLimit() && $plan['key'] === 'paid')
        <div class="rounded-xl border border-zinc-200 bg-white p-4 text-sm text-zinc-800 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
            You used the {{ number_format($plan['submissions']) }} submissions your forms include this month. Every message is still emailed. Each extra 1,000 costs {{ \App\Support\Bill::format(config('billing.per_form.extra_eur')) }}, added to your next invoice once the month ends.
        </div>
    @elseif ($user->overSubmissionLimit())
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
            You reached this month's {{ number_format($plan['submissions']) }} submissions. We keep emailing new ones until {{ number_format($user->emailCutoff()) }}, then they are only saved here.
            @if ($plan['key'] === 'trial')<a href="{{ route('billing') }}" class="font-medium underline" wire:navigate>Subscribe</a> to keep every message emailed.@endif
        </div>
    @endif
    {{-- Days left on the trial show in the top bar on every page, so no banner here. --}}
@endif
