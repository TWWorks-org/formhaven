@php($p = config('billing.per_form'))
@php($bill = \App\Support\Bill::class)
@php($one = $bill::format($p['monthly_eur']))
@php($lower = $bill::format($p['lower_monthly_eur']))
@php($from = $bill::firstLowerForm())
@php($trial = config('billing.trial_days'))
@php($grace = (int) round(config('billing.email_grace') * 100))
@php($eyebrow = 'font-mono text-xs tracking-[0.12em] text-zinc-600 uppercase')
@php($h2 = 'text-3xl leading-tight font-semibold tracking-tight sm:text-[2.375rem]')
@php($band = '-mx-6 border-y border-zinc-200 bg-zinc-50 px-6 py-16 sm:-mx-8 sm:px-8 sm:py-22 xl:-mx-12 xl:px-12')
{{-- Billing questions: [question, answer] pairs of plain text. The visible FAQ and its FAQPage schema both read this array.
     Inline @php( ) on purpose: a @php block in a file that also uses inline @php( ) swallows the lines between them. --}}
@php($faq = [
        ['Why per form?', 'Every account gets every feature, so the only fair difference is how much you use. You know how many client sites you run, so you can work out your bill before you start.'],
        ['Why is there no free plan?', 'You get '.$trial.' days instead, with up to '.config('billing.trial_forms').' forms and no card. That is enough to build and launch a client site and see real messages arrive before you pay anything. During the trial, emails pause '.$grace.'% past the included submissions; once you subscribe they never do.'],
        ['I have only one or two forms. What do I pay?', 'The minimum: '.$bill::format($bill::monthly($p['min_forms'])).' a month or '.$bill::format($bill::yearly($p['min_forms'])).' a year, the price of '.$p['min_forms'].' forms, with '.number_format($p['min_forms'] * $p['submissions']).' submissions a month included.'],
        ['I run a lot of client sites. Does it get cheaper?', 'Yes. The first '.$p['full_price_forms'].' forms are '.$one.' each a month. Every form from the '.$from.' is '.$lower.', and still includes '.number_format($p['submissions']).' submissions a month. 30 forms come to '.$bill::format($bill::monthly(30)).' a month, 100 forms to '.$bill::format($bill::monthly(100)).' a month.'],
        ['What if my agent creates forms?', 'Each form it creates adds '.$one.' to your monthly bill once you are past '.$p['min_forms'].', or '.$lower.' from the '.$from.' form, the same as one you create yourself. Your agent is told this, and you can delete any form from the dashboard.'],
        ['Can I cancel?', 'Cancel any time from the billing page. Your forms keep their addresses.'],
])
<x-layouts.site
    title="Pricing: {{ $one }} per form a month, {{ $lower }} from the {{ $from }} | FormHaven"
    description="{{ $one }} per form a month, {{ $lower }} from the {{ $from }} form, every feature included. Every form includes {{ number_format($p['submissions']) }} submissions, stored in Germany, spam filtered and emailed to you. {{ $trial }}-day free trial, no card needed.">
    <x-slot:jsonLd>
        @include('partials.schema-faq', ['questions' => $faq])
    </x-slot:jsonLd>

    <x-site.hero
        eyebrow="Pricing"
        title="{{ $one }} per form a month. From the {{ $from }} form, {{ $lower }}."
        subtitle="Every feature on every form. You pay for the client sites you run, nothing else: add a form and the bill goes up by {{ $one }}, or by {{ $lower }} from the {{ $from }} form; delete one and it goes down. Try it free for {{ $trial }} days, no card needed." />

    <div class="pb-16 sm:pb-22">
        <x-site.price-calculator />
    </div>

    <section class="{{ $band }} flex flex-wrap gap-12">
        <div class="flex min-w-0 flex-[1_1_18rem] flex-col gap-3.5">
            <p class="{{ $eyebrow }}">The whole price list</p>
            <h2 class="{{ $h2 }}">Four rates. That is all of them.</h2>
            <p class="max-w-md text-zinc-700">An AI agent reading this page gets the same list in <a href="{{ url('/llms.txt') }}" class="underline">llms.txt</a>.</p>
        </div>
        <div class="flex min-w-0 flex-[2_1_34rem] flex-col gap-10">
            <x-site.rate-card />
            <div class="flex flex-col gap-4">
                <h3 class="text-xl font-semibold">What common form counts come to</h3>
                <x-site.price-samples />
                <p class="text-sm text-zinc-600">Spam never counts as a submission.</p>
            </div>
        </div>
    </section>

    <section class="flex flex-col gap-8 py-16 sm:py-22">
        <div class="flex flex-col gap-3">
            <p class="{{ $eyebrow }}">Every feature, every account</p>
            <h2 class="{{ $h2 }}">On every form</h2>
        </div>
        <div class="grid border-t border-l border-t-zinc-900 border-l-zinc-200 sm:grid-cols-2 lg:grid-cols-3">
            <div class="border-r border-b border-zinc-200 p-6">
                <h3 class="{{ $eyebrow }} text-[0.6875rem]">Storage and paperwork</h3>
                <ul class="mt-3 flex flex-col gap-2 text-zinc-800">
                    <li>Stored on EU servers in Germany</li>
                    <li><a href="{{ route('legal', 'dpa') }}" class="underline">Data processing agreement</a></li>
                    <li>Public <a href="{{ route('legal', 'subprocessors') }}" class="underline">subprocessor list</a></li>
                    <li>Retention per form, 1 to 365 days</li>
                    <li>Privacy text per form, in English, Dutch and German</li>
                </ul>
            </div>
            <div class="border-r border-b border-zinc-200 p-6">
                <h3 class="{{ $eyebrow }} text-[0.6875rem]">Delivery</h3>
                <ul class="mt-3 flex flex-col gap-2 text-zinc-800">
                    <li>Email to your inbox, Reply goes to the visitor</li>
                    <li>An email when an inbox refuses a message, then Send again</li>
                    <li>Copies to your client, under your agency's name</li>
                    <li>A Monday summary of every form</li>
                    <li>Warning emails at 80% and 100% of your included submissions</li>
                </ul>
            </div>
            <div class="border-r border-b border-zinc-200 p-6">
                <h3 class="{{ $eyebrow }} text-[0.6875rem]">Spam and setup</h3>
                <ul class="mt-3 flex flex-col gap-2 text-zinc-800">
                    <li>Spam trap, link filter and invisible bot check</li>
                    <li>Allowed sites per form</li>
                    <li>Your own thank-you page</li>
                    <li>JSON responses for fetch forms</li>
                    <li>Agent setup without an account, REST API and MCP server</li>
                </ul>
            </div>
        </div>
    </section>

    <section class="{{ $band }} flex flex-col gap-8">
        <h2 class="{{ $h2 }}">What happens when</h2>
        <div class="grid gap-5 md:grid-cols-2">
            @foreach ([
                'A client site has a busy month' => [
                    'We email you at 80% and 100% of the submissions your forms include.',
                    'Every message is still emailed. Nobody gets cut off.',
                    'Each extra 1,000 costs '.$bill::format($p['extra_eur']).', added to your next invoice once the month ends. Spam never counts.',
                ],
                'A client site goes away' => [
                    'Delete its form from the dashboard, or let your agent do it.',
                    'The form and its stored submissions are deleted.',
                    'Your bill counts one form fewer from that day, down to the minimum of '.$p['min_forms'].'.',
                ],
                'An inbox refuses a message' => [
                    'The message stays in your dashboard, even on a form set to keep nothing.',
                    'You get an email naming the address and the reason.',
                    'Fix the address and press Send again. The waiting messages go out.',
                ],
                'Your trial ends' => [
                    'Subscribe and nothing changes for your visitors. Forms keep their addresses.',
                    'Without a subscription, forms pause and visitors see a short message that the form is paused.',
                    'So nobody thinks their message arrived when it did not.',
                ],
            ] as $heading => $steps)
                <div class="rounded border border-zinc-300 bg-white">
                    <h3 class="border-b border-zinc-200 px-6 py-4 text-lg font-semibold">{{ $heading }}</h3>
                    <ol>
                        @foreach ($steps as $step)
                            <li @class(['flex gap-4 px-6 py-3.5', 'border-b border-zinc-200' => ! $loop->last])>
                                <span class="flex-none pt-0.5 font-mono text-xs text-zinc-600 tabular-nums">{{ sprintf('%02d', $loop->iteration) }}</span>
                                <p class="text-zinc-700">{{ $step }}</p>
                            </li>
                        @endforeach
                    </ol>
                </div>
            @endforeach
        </div>
    </section>

    <section class="flex flex-wrap gap-10 py-16 sm:py-22">
        <h2 class="{{ $h2 }} flex-[1_1_15rem]">Billing questions</h2>
        <dl class="flex min-w-0 flex-[3_1_32rem] flex-col border-b border-zinc-200">
            @foreach ($faq as [$question, $answer])
                <div @class(['border-t py-5', 'border-zinc-900' => $loop->first, 'border-zinc-200' => ! $loop->first])>
                    <dt class="font-semibold">{{ $question }}</dt>
                    <dd class="mt-1.5 max-w-3xl text-zinc-700">{{ $answer }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <x-site.cta-band title="Put your next client site on it" />
</x-layouts.site>
