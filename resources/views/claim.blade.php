@php($taken = $taken ?? false)
<x-layouts.site title="Activate your form: FormHaven" description="Switch on the contact form your AI agent set up with FormHaven.">
    <section class="max-w-2xl pt-10">
    @if ($expired ?? false)
        <h1 class="text-3xl font-semibold tracking-tight">This activation link has expired</h1>
        <p class="mt-3 text-zinc-600">Links work for {{ \App\Models\FormClaim::DAYS }} days and only until the form is switched on. Ask your agent to start the form again, and it gets you a new link.</p>
    @else
        <p class="text-sm font-medium tracking-wide text-zinc-600 uppercase">Set up by your agent</p>
        <h1 class="mt-3 text-3xl font-semibold tracking-tight">{{ ($activated ?? false) ? 'Your form is active' : ($taken ? 'This form is already active' : 'Activate "'.$claim->name.'"') }}</h1>

        @if ($activated ?? false)
            <p class="mt-3 text-zinc-600">"{{ $claim->name }}" now stores every message it gets, and your agent can finish the setup with the key it holds. Confirm your email address with the link we sent you. From then on, messages are emailed to you and you can read them in your dashboard.</p>
            <a href="{{ route('verification.notice') }}" class="mt-6 inline-block rounded-lg bg-zinc-900 px-5 py-3 font-medium text-white">Confirm my email address</a>
        @elseif ($taken)
            <p class="mt-3 text-zinc-600">Someone has already activated this form on their FormHaven account. If that was you, log in with that account to see it.</p>
            @guest
                <a href="{{ route('login') }}" class="mt-6 inline-block rounded-lg bg-zinc-900 px-5 py-3 font-medium text-white">Log in</a>
            @endguest
        @else
            <p class="mt-3 text-zinc-600">Your agent reserved a form address{{ $claim->site_url ? ' for '.parse_url($claim->site_url, PHP_URL_HOST) : '' }} and put it in your site. It accepts nothing until you switch it on. After that, every message is stored {{ \App\Support\Mode::hosted() ? 'on our servers' : 'on this install' }}{{ \App\Support\Operator::storageCountry() ? ' in '.\App\Support\Operator::storageCountry() : '' }}, filtered for spam and emailed to you. Your agent finishes the setup with the key it holds.</p>

            <dl class="mt-6 grid gap-2 rounded-xl border border-zinc-200 p-5 text-sm sm:grid-cols-[10rem_1fr]">
                <dt class="text-zinc-600">Form address</dt>
                <dd class="font-mono break-all">{{ route('submit', $claim->public_id) }}</dd>
                @if ($claim->client)
                    <dt class="text-zinc-600">The agent called itself</dt>
                    <dd class="break-all">{{ $claim->client }}</dd>
                @endif
                @if ($claim->site_url)
                    <dt class="text-zinc-600">Website</dt>
                    <dd class="break-all">{{ $claim->site_url }}</dd>
                @endif
                <dt class="text-zinc-600">Link valid until</dt>
                <dd>{{ $claim->expiresAt()->toFormattedDayDateString() }}</dd>
            </dl>

            @error('claim')
                <p class="mt-6 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">{{ $message }}@if (\App\Support\Mode::hosted()) <a href="{{ route('billing') }}" class="underline">Go to Billing</a>@endif</p>
            @enderror

            @auth
                @if (auth()->user()->hasVerifiedEmail())
                    <form action="{{ route('claim.store', $token) }}" method="POST" class="mt-8 flex flex-col gap-3">
                        @csrf
                        <button type="submit" class="self-start rounded-lg bg-zinc-900 px-5 py-3 font-medium text-white">Activate this form</button>
                        <p class="text-sm text-zinc-600">It goes on {{ auth()->user()->email }}'s account. Your agent's key reaches this form only, never your other forms, works for {{ config('sanctum.setup_key_days') }} days, and you can revoke it on the form's page.</p>
                    </form>
                @else
                    <div class="mt-8 flex flex-col gap-3" data-verify-first>
                        <p class="font-medium">First confirm your email address</p>
                        <p class="text-sm text-zinc-600">We sent a link to {{ auth()->user()->email }}. Press it, and you come back to this page to press Activate. This page works until {{ $claim->expiresAt()->toFormattedDayDateString() }}.</p>
                        <a href="{{ route('verification.notice') }}" class="self-start rounded-lg bg-zinc-900 px-5 py-3 font-medium text-white">Confirm my email address</a>
                    </div>
                @endif
            @else
                <div class="mt-8 flex flex-col gap-3">
                    <p class="font-medium">To switch it on, use a FormHaven account</p>
                    <div class="flex flex-wrap gap-3">
@if (\App\Support\Mode::registrationOpen())
                        <a href="{{ route('register') }}" class="rounded-lg bg-zinc-900 px-5 py-3 font-medium text-white">Create an account</a>
@endif
                        <a href="{{ route('login') }}" class="rounded-lg border border-zinc-300 px-5 py-3 font-medium">Log in</a>
                    </div>
@if (\App\Support\Mode::hosted())
                    <p class="text-sm text-zinc-600">New accounts get a {{ config('billing.trial_days') }}-day trial, no card needed. You come back to this page afterwards to press Activate.</p>
@else
                    <p class="text-sm text-zinc-600">You come back to this page afterwards to press Activate.</p>
@endif
                </div>
            @endauth
        @endif
    @endif
    </section>
</x-layouts.site>
