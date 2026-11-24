<x-layouts.site title="Report a form: FormHaven" description="Report a FormHaven form that is used for phishing, fraud, spam or other illegal content. We review every report by hand and email you the decision.">
    <section class="max-w-2xl pt-10">
        <h1 class="text-3xl font-semibold tracking-tight">Report a form</h1>
        <p class="mt-3 text-zinc-600">Seen a form that sends its answers to FormHaven and is used for phishing, fraud, spam or anything else illegal? Tell us here. We review every report by hand, email you a receipt now and our decision later, with the reasons.</p>

        @if (session('reported') === true)
            <p class="mt-8 rounded-lg border border-zinc-300 bg-zinc-50 px-4 py-3" role="status">Thank you. Your report is in, and a receipt is on its way to your inbox.</p>
        @elseif (session('reported'))
            {{-- Today's mail budget for reports was used up: the report is stored, only the receipt email is not sent. --}}
            <p class="mt-8 rounded-lg border border-zinc-300 bg-zinc-50 px-4 py-3" role="status">Thank you. Your report is in as number {{ (int) session('reported') }}. We could not email you a receipt today, so keep this number: we email our decision to the address you gave.</p>
        @else
            <form action="{{ route('report.store') }}" method="POST" class="mt-8 flex flex-col gap-5">
                @csrf
                <label class="flex flex-col gap-1.5 text-sm font-medium">Where is the form?
                    <span class="font-normal text-zinc-500">The web address of the page with the form, or the form's address on {{ parse_url(config('app.url'), PHP_URL_HOST) }}/f/...</span>
                    <input type="text" name="url" value="{{ old('url') }}" required maxlength="2000" placeholder="https://example.com/contact" class="rounded-lg border border-zinc-300 bg-white px-3.5 py-3 text-base font-normal">
                    @error('url')<span class="font-normal text-red-700">{{ $message }}</span>@enderror
                </label>
                <label class="flex flex-col gap-1.5 text-sm font-medium">Why is it illegal or abusive?
                    <textarea name="reason" rows="5" required maxlength="5000" class="rounded-lg border border-zinc-300 bg-white px-3.5 py-3 text-base font-normal">{{ old('reason') }}</textarea>
                    @error('reason')<span class="font-normal text-red-700">{{ $message }}</span>@enderror
                </label>
                <div class="grid gap-5 sm:grid-cols-2">
                    <label class="flex flex-col gap-1.5 text-sm font-medium">Your name
                        <input type="text" name="name" value="{{ old('name') }}" required maxlength="255" autocomplete="name" class="rounded-lg border border-zinc-300 bg-white px-3.5 py-3 text-base font-normal">
                        @error('name')<span class="font-normal text-red-700">{{ $message }}</span>@enderror
                    </label>
                    <label class="flex flex-col gap-1.5 text-sm font-medium">Your email address
                        <input type="email" name="email" value="{{ old('email') }}" required maxlength="255" autocomplete="email" class="rounded-lg border border-zinc-300 bg-white px-3.5 py-3 text-base font-normal">
                        @error('email')<span class="font-normal text-red-700">{{ $message }}</span>@enderror
                    </label>
                </div>
                <input type="text" name="_gotcha" hidden tabindex="-1" autocomplete="off">
                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" name="good_faith" value="1" @checked(old('good_faith')) required class="mt-1">
                    <span>I believe in good faith that the information in this report is accurate and complete.</span>
                </label>
                @error('good_faith')<span class="-mt-3 text-sm text-red-700">{{ $message }}</span>@enderror
                <button type="submit" class="self-start rounded-lg bg-zinc-900 px-5 py-3 font-medium text-white">Send report</button>
            </form>
        @endif

        <p class="mt-10 text-sm text-zinc-500">Reports are read in English or Dutch. Authorities can use the same form or email {{ config('legal.email') }}. We keep reports to handle them and to show what we decided, as set out in our <a href="{{ route('legal', 'privacy') }}" class="underline">privacy policy</a>.</p>
    </section>
</x-layouts.site>
