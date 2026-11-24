@props(['title', 'text' => null, 'button' => null])
{{-- The closing call to action on every public page: pages pass copy, the action is always the trial.
     It is the last block in <main>: it runs edge to edge between the frame lines and cancels main's
     bottom padding, so it meets the footer. --}}
@php($trial = config('billing.trial_days'))
<section class="-mx-6 mt-16 -mb-20 flex flex-wrap items-center justify-between gap-6 bg-zinc-900 px-6 py-14 text-white sm:-mx-8 sm:px-8 sm:py-16 xl:-mx-12 xl:px-12">
    <div class="flex min-w-0 flex-col gap-2">
        <h2 class="text-2xl font-semibold tracking-tight text-balance sm:text-[2.125rem] sm:leading-tight">{{ $title }}</h2>
        <p class="text-zinc-300">{{ $text ?? $trial.' days free, no card. Then '.\App\Support\Bill::format(config('billing.per_form.monthly_eur')).' per form a month, '.\App\Support\Bill::format(config('billing.per_form.lower_monthly_eur')).' from the '.\App\Support\Bill::firstLowerForm().' form.' }}</p>
    </div>
    <a href="{{ route('register') }}" class="rounded bg-white px-5 py-3.5 font-medium text-zinc-900 hover:bg-zinc-200">{{ $button ?? 'Start your '.$trial.'-day trial' }}</a>
</section>
