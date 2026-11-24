@php($perForm = config('billing.per_form'))
@php($bill = \App\Support\Bill::class)
@php($one = $bill::format($perForm['monthly_eur']))
@php($lower = $bill::format($perForm['lower_monthly_eur']))
@php($from = $bill::firstLowerForm())
@php($trial = config('billing.trial_days'))
@php($grace = (int) round(config('billing.email_grace') * 100))
@php($notices = collect(config('billing.usage_notices'))->map(fn ($n) => $n.'%')->join(', ', ' and '))
@php($place = config('legal.hosting.place.en'))
@php($country = trim(explode(',', $place)[1]))
@php($provider = config('legal.hosting.provider'))
{{-- Retention limits from the rules every create and update path validates against. --}}
@php($ruleValue = fn (string $name) => (int) \Illuminate\Support\Str::after(collect(\App\Actions\CreateForm::RULES['retention_days'])->first(fn ($rule) => str_starts_with($rule, $name.':')), ':'))
@php($minDays = $ruleValue('min'))
@php($maxDays = $ruleValue('max'))
@php($defaultDays = \App\Models\Form::DEFAULT_RETENTION_DAYS)
@php($heroCode = implode("\n", [
    'Add a working contact form to this site with FormHaven.',
    'Read '.url('/llms.txt').' and follow',
    '"Start without an account". Then give me the link',
    'to switch the form on.',
]))
@php($tools = \App\Mcp\Servers\FormHavenServer::toolNames())

{{-- Example data for the mock dashboard, digest and spam cards. Every sentence in them comes from the
     code that writes it for real (Form, DeliveryFailure, Submission, PrivacyText); only the names,
     addresses and counts are made up. --}}
@php($bounced = (new \App\Models\DeliveryFailure)->forceFill(['kind' => \App\Mail\DeliveryRefused::BOUNCED]))
@php($bounceEmail = 'frontdesk@example.com')
@php($bounceCount = 2)
@php($bounce = ['message' => $bounceCount.' messages did not reach '.$bounceEmail.'. '.$bounced->explain(), 'fix' => $bounced->fix()])
@php($quietForm = (new \App\Models\Form)->forceFill(['typical_gap_hours' => 48]))
@php($quietDays = max(\App\Models\Form::QUIET_MIN_DAYS, (int) ceil(\App\Models\Form::QUIET_GAP_FACTOR * $quietForm->typical_gap_hours / 24)) + 1)
@php($quiet = ['message' => 'No messages for '.$quietDays.' days. This form usually gets one every '.$quietForm->typicalGap().'.', 'fix' => 'Send a test from the site itself to check the form still works.'])
@php($exampleSites = [
    'bakery.example.com' => [['name' => 'Contact', 'id' => 'k3v9q2mz7hx1wd4p', 'last' => now()->subMinutes(12), 'month' => 142, 'week' => 38, 'before' => 31, 'spam' => 12, 'status' => 'active']],
    'estate-agent.example.net' => [
        ['name' => 'Viewing request', 'id' => 'p8c2tn5wq0jr6ybe', 'last' => now()->subHour(), 'month' => 97, 'week' => 24, 'before' => 20, 'spam' => 41, 'status' => 'active'],
        ['name' => 'Valuation', 'id' => 'h4m7zd1sx9fk3vla', 'last' => null, 'month' => 0, 'week' => 0, 'before' => 0, 'spam' => 0, 'status' => 'waiting'],
    ],
    'dentist.example.com' => [['name' => 'Booking', 'id' => 'r6w1bq8ny2gc5tmo', 'last' => now()->subHours(3), 'month' => 35, 'week' => 9, 'before' => 11, 'spam' => 3, 'status' => 'attention', 'problem' => $bounce]],
    'studio.example.net' => [['name' => 'Contact', 'id' => 'e2j9xk4vp7ud0sqa', 'last' => now()->subDays($quietDays), 'month' => 3, 'week' => 0, 'before' => 2, 'spam' => 7, 'status' => 'quiet', 'problem' => $quiet]],
])
@php($allForms = collect($exampleSites)->flatMap(fn ($forms, $host) => collect($forms)->map(fn ($f) => $f + ['host' => $host])))
@php($digestWeek = $allForms->sum('week'))
@php($digestLook = $allForms->whereNotNull('problem'))
@php($spamRows = collect([
    ['seo-offers@example.net', 'Rank first on Google today [url=https://example.net]', 'link_markup'],
    ['bot-4471@example.net', 'Great website, check my offer', 'honeypot'],
    ['sales@example.com', 'We build websites cheap, reply now', 'spam_word:sales@example.com'],
    ['deals@example.net', 'Buy followers today, five links inside', 'links'],
])->map(fn ($r) => ['from' => $r[0], 'text' => $r[1], 'why' => (new \App\Models\Submission)->forceFill(['spam' => true, 'spam_reason' => $r[2]])->spamReason()]))
@php($privacyForm = new \App\Models\Form(['retention_days' => $defaultDays]))
@php($privacy = collect(\App\Support\PrivacyText::LANGUAGES)->mapWithKeys(fn ($lang) => [$lang => \App\Support\PrivacyText::for($privacyForm, $lang)]))
@php($highlight = fn (string $lang, string $paragraph) => preg_replace(
    ['/'.preg_quote(e(config("legal.hosting.place.{$lang}")), '/').'/u', '/\b'.$defaultDays.' (days|dagen|Tagen)\b/u'],
    '<mark class="rounded-sm bg-green-50 px-0.5 text-green-800">$0</mark>',
    e($paragraph)))
@php($examples = [$perForm['min_forms'], $perForm['full_price_forms'], 25, 50, 100])
{{-- The FAQ: [question, answer as HTML] pairs. The visible list and its FAQPage schema (as plain text) both read this array.
     Inline @php( ) on purpose: a @php block in a file that also uses inline @php( ) swallows the lines between them. --}}
@php($faq = [
        ['Is there a free plan?', e('No. Every account gets '.$trial.' days without a card, then it is '.$one.' per form a month, for at least '.$perForm['min_forms'].' forms, and '.$lower.' per form from the '.$from.'.')],
        ['Does it work with my site builder?', 'If your site can send an HTML form or a fetch request, it works: plain HTML, static site generators and AI-built sites alike. No plugin, no server code.'],
        ['Is the form address a secret?', 'No. It sits in your page\'s HTML for anyone to see, the same as with any form backend. A rate limit and the spam trap stop anyone flooding it, and you can list your sites per form so a copy on any other site goes to spam.'],
        ['Can an AI agent set it up for me?', 'Yes. Paste the prompt at the top of this page into your agent. It puts the form\'s address in your site and gives you a link. Open it, log in or create an account, and press Activate. After that the agent can change any setting and read the submissions through our MCP server or REST API. The details are in <a href="'.e(route('llms')).'" class="underline">llms.txt</a>.'],
        ['Does my agent need an account or an API key?', 'No. It starts the form without one, and the key it gets works once you switch the form on. In Claude, add our server as a custom connector and sign in to FormHaven when it asks. You can also create an API key and connect any MCP client, such as Claude Code or Cursor.'],
        ['What happens if an email bounces?', 'The message stays in your dashboard, even on a form set to keep nothing, and you get an email naming the address and the reason. Fix the address and press Send again. A mailbox that was closed shows up from the second message on: the first one is accepted and bounces later, where we cannot see it.'],
        ['What happens when my trial ends?', 'Subscribe and nothing changes for your visitors. Without a subscription, your forms pause and visitors see a short message saying the form is paused, so nobody thinks their message arrived when it did not.'],
        ['What if I go over my monthly submissions?', e('We email you at '.$notices.' of the submissions your forms include. Past that, every message is still emailed, and each extra 1,000 costs '.$bill::format($perForm['extra_eur']).', added to your next invoice once the month ends. During the trial, emails pause '.$grace.'% past the included submissions and new messages are still saved in your dashboard.')],
])

<x-layouts.site
    :title="'EU Form Backend, Stored in '.$country.' | FormHaven for AI Agents'"
    description="Your AI agent sets up the form and hands you the privacy text. Messages are stored in {{ $country }} and emailed to you, and you hear it when one bounces. {{ $trial }}-day free trial, no card.">
    <x-slot:jsonLd>
        @include('partials.schema-faq', ['questions' => array_map(fn (array $qa) => [$qa[0], html_entity_decode(strip_tags($qa[1]), ENT_QUOTES | ENT_HTML5)], $faq)])
    </x-slot:jsonLd>
    <x-site.hero
        :eyebrow="'Stored in '.$place"
        title="The EU form backend your AI agent sets up."
        :subtitle="'Paste one prompt into Claude Code and your agent wires the form into your site. Every message is saved in '.$country.' and emailed to you, and you hear it when an inbox refuses one.'"
        :cta="['href' => route('register'), 'label' => 'Start your '.$trial.'-day trial', 'note' => 'No card needed. Then '.$one.' per form a month, '.$lower.' from the '.$from.' form, every feature included.']"
        :secondary="['href' => '#demo', 'label' => 'Send a test message']"
        code-label="Paste into your agent"
        :code="$heroCode" />

    {{-- At a glance: four ruled cells. gap-px on a zinc-200 grid draws the rules at any column count. --}}
    <section aria-label="At a glance" class="-mx-6 grid gap-px border-y border-zinc-900 bg-zinc-200 sm:-mx-8 sm:grid-cols-2 xl:-mx-12 xl:grid-cols-4">
        @foreach ([
            ['Stored in', $place, $provider],
            ['Kept for', $defaultDays.' days', 'Or '.$minDays.' to '.$maxDays.', set per form'],
            ['Included', number_format($perForm['submissions']).' submissions', 'Per form a month, shared'],
            ['Price', $one.' per form a month', $lower.' from the '.$from.' form'],
        ] as [$label, $value, $note])
            <div class="bg-white px-6 py-5 sm:px-8 xl:px-12">
                <p class="font-mono text-[0.6875rem] tracking-[0.12em] text-zinc-600 uppercase">{{ $label }}</p>
                <p class="mt-1.5 text-xl font-semibold">{{ $value }}</p>
                <p class="mt-0.5 text-sm text-zinc-600">{{ $note }}</p>
            </div>
        @endforeach
    </section>

    <section aria-labelledby="works-with" class="-mx-6 sm:-mx-8 xl:-mx-12">
        <p id="works-with" class="px-6 py-4.5 font-mono text-[0.6875rem] tracking-[0.12em] text-zinc-600 uppercase sm:px-8 xl:px-12">Works wherever a form can POST</p>
        <ul class="grid grid-cols-2 gap-px border-y border-zinc-200 bg-zinc-200 text-center font-medium sm:grid-cols-4 xl:grid-cols-8">
            @foreach (['Plain HTML', 'Astro', 'Hugo', 'Eleventy', 'Next.js', 'Lovable', 'Bolt', 'Claude Code'] as $stack)
                <li class="bg-white px-3 py-5">{{ $stack }}</li>
            @endforeach
        </ul>
    </section>

    {{-- The dashboard's forms list, with the dashboard's own columns and status badges. --}}
    <section aria-labelledby="your-forms" class="flex flex-col gap-7 py-20">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="flex min-w-0 flex-col gap-3">
                <x-site.eyebrow>Your forms</x-site.eyebrow>
                <h2 id="your-forms" class="max-w-3xl text-3xl leading-tight font-semibold tracking-tight text-balance sm:text-[2.375rem]">Every client site in one list, and every form says how it is doing</h2>
                <p class="max-w-2xl text-zinc-700">One login shows every client site. A form whose messages an inbox refused says so, and so does one that went quiet. The refused message stays saved until you press Send again.</p>
            </div>
            <x-site.tag>Example data</x-site.tag>
        </div>
        <div class="overflow-x-auto rounded border border-zinc-900">
            <table class="w-full min-w-[46rem] text-left text-sm">
                <caption class="sr-only">Example of the forms list in the dashboard</caption>
                <thead class="bg-zinc-50 font-mono text-[0.6875rem] tracking-[0.1em] text-zinc-600 uppercase">
                    <tr>
                        <th scope="col" class="border-b border-zinc-900 px-4 py-3 font-medium">Site and form</th>
                        <th scope="col" class="border-b border-zinc-900 px-4 py-3 font-medium">Address</th>
                        <th scope="col" class="border-b border-zinc-900 px-4 py-3 font-medium">Last submission</th>
                        <th scope="col" class="border-b border-zinc-900 px-4 py-3 text-right font-medium">This month</th>
                        <th scope="col" class="border-b border-zinc-900 px-4 py-3 font-medium">Status</th>
                    </tr>
                </thead>
                @foreach ($exampleSites as $host => $forms)
                    @php($lastAt = collect($forms)->pluck('last')->filter()->max())
                    <tbody class="border-t border-zinc-200 first-of-type:border-t-0">
                        <tr>
                            <th scope="rowgroup" class="px-4 py-3 font-semibold whitespace-nowrap">{{ $host }} <span class="font-normal text-zinc-600">· {{ count($forms) }} {{ Str::plural('form', count($forms)) }}</span></th>
                            <td></td>
                            <td class="px-4 py-3 whitespace-nowrap text-zinc-600">{{ $lastAt?->diffForHumans() }}</td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ number_format(collect($forms)->sum('month')) }}</td>
                            <td></td>
                        </tr>
                        @foreach ($forms as $form)
                            <tr class="border-t border-zinc-100 bg-zinc-50/60">
                                <td class="py-3 pr-4 pl-9 font-medium whitespace-nowrap">{{ $form['name'] }}</td>
                                <td class="px-4 py-3 font-mono text-xs whitespace-nowrap text-zinc-600">/f/{{ $form['id'] }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-zinc-600">{{ $form['last']?->diffForHumans() ?? 'None yet' }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ number_format($form['month']) }}</td>
                                <td class="px-4 py-3"><x-app.status-badge :status="$form['status']" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            </table>
        </div>
    </section>

    <x-site.band id="agents" class="flex flex-wrap items-center gap-x-14 gap-y-10 py-20">
        <div class="flex min-w-0 flex-[1_1_26rem] flex-col gap-4">
            <x-site.eyebrow>Set up by your agent</x-site.eyebrow>
            <h2 class="text-3xl leading-tight font-semibold tracking-tight text-balance sm:text-[2.375rem]">One prompt in, a working form out</h2>
            <p class="max-w-xl text-zinc-700">Your agent needs no account to start. One request reserves the form, and you switch it on from the link it sends you. After that it changes any setting, gets the code for HTML, React or Astro and reads the submissions back.</p>
            <ol class="mt-2 flex flex-col">
                @foreach ([
                    ['Paste the prompt', 'into Claude Code, Cursor or any agent that can read a web page.'],
                    ['Press Activate', 'on the link it gives you. Log in or create an account there.'],
                    ['Answer by pressing Reply.', 'Each message arrives with the visitor as the reply address.'],
                ] as $i => [$strong, $rest])
                    <li class="flex gap-4 border-t border-zinc-200 py-3.5 last:border-b">
                        <span class="font-mono text-sm text-zinc-600">{{ sprintf('%02d', $i + 1) }}</span>
                        <span><strong class="font-semibold">{{ $strong }}</strong> {{ $rest }}</span>
                    </li>
                @endforeach
            </ol>
        </div>
        <div class="min-w-0 flex-[1_1_28rem] overflow-hidden rounded border border-zinc-900 bg-white">
            <div class="flex items-center justify-between gap-3 border-b border-zinc-200 px-5 py-3.5">
                <p class="font-semibold">Agent session</p>
                <x-site.tag>Illustrative</x-site.tag>
            </div>
            <div class="flex flex-col gap-3.5 p-5">
                <p class="max-w-[90%] self-end rounded bg-zinc-100 [overflow-wrap:anywhere] px-3.5 py-3 text-[0.9375rem]">{{ str_replace("\n", ' ', $heroCode) }}</p>
                <div class="rounded border border-zinc-200 font-mono text-[0.8125rem] leading-relaxed">
                    <p class="border-b border-zinc-200 px-3.5 py-2.5 text-zinc-600">request</p>
                    <p class="border-b border-zinc-200 px-3.5 py-2.5 break-all">POST {{ url('/api/v1/start') }}</p>
                    <p class="px-3.5 py-2.5 text-green-800">201 "status": "waiting_for_owner"</p>
                </div>
                <p class="text-[0.9375rem] text-zinc-700">I added the form to contact.html and set its action to your FormHaven address. Open this link and press Activate to switch it on.</p>
            </div>
            <p class="border-t border-zinc-200 px-5 py-3 text-sm text-zinc-600">In Claude, add <span class="font-mono break-all">{{ url('/mcp') }}</span> as a custom connector and sign in. No key to copy.</p>
        </div>
    </x-site.band>

    <x-site.band tone="dark" class="flex flex-wrap gap-x-14 gap-y-10 py-16">
        <div class="flex min-w-0 flex-[1_1_24rem] flex-col gap-4">
            <x-site.eyebrow dark>MCP server and REST API</x-site.eyebrow>
            <h2 class="text-2xl leading-tight font-semibold tracking-tight text-white sm:text-3xl">Everything after setup, your agent can do too</h2>
            <p class="max-w-xl text-zinc-300">It hands you the privacy text for your site in English, Dutch or German, filled in from the form's real settings. The same works through a plain REST API. Everything an agent needs to start is in <a href="{{ route('llms') }}" class="text-white underline">llms.txt</a>.</p>
        </div>
        <div class="flex min-w-0 flex-[1_1_28rem] flex-col gap-3.5">
            <p id="mcp-tools" class="font-mono text-[0.6875rem] tracking-[0.12em] text-zinc-300 uppercase">MCP tools</p>
            <ul data-tools aria-labelledby="mcp-tools" class="grid grid-cols-2 border-t border-l border-zinc-700 font-mono text-xs sm:grid-cols-[repeat(auto-fill,minmax(11rem,1fr))] sm:text-[0.8125rem]">
                @foreach ($tools as $tool)
                    <li class="border-r border-b border-zinc-700 px-3 py-3 text-zinc-100 [overflow-wrap:anywhere] sm:px-3.5">{{ $tool }}</li>
                @endforeach
            </ul>
        </div>
    </x-site.band>

    <x-site.band tone="tint" class="flex flex-col gap-20 py-20 sm:gap-24">
        <div class="flex max-w-3xl flex-col gap-3">
            <x-site.eyebrow>After the form is live</x-site.eyebrow>
            <h2 class="text-3xl leading-tight font-semibold tracking-tight text-balance sm:text-[2.375rem]">Your agent moves on. FormHaven keeps watching the form.</h2>
        </div>

        {{-- 1: a refused delivery, as the form's page shows it --}}
        <div class="flex flex-wrap items-center gap-x-14 gap-y-8">
            <div class="flex min-w-0 flex-[1_1_24rem] flex-col gap-3">
                <h3 class="text-2xl leading-tight font-semibold tracking-tight sm:text-[1.75rem]">An email when a client's inbox refuses a message</h3>
                <p class="max-w-lg text-zinc-700">You get an email saying which address and why. The message waits in your dashboard, even on a form set to keep nothing, and Send again delivers it once the inbox works.</p>
            </div>
            <div class="min-w-0 flex-[1_1_28rem] overflow-hidden rounded border border-zinc-900 bg-white">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 px-5 py-3.5">
                    <p class="flex flex-wrap items-center gap-3 font-semibold">Booking <span class="font-normal text-zinc-600">dentist.example.com</span> <x-app.status-badge status="attention" /></p>
                    <x-site.tag>Example data</x-site.tag>
                </div>
                <div class="m-5 rounded border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                    <p class="font-semibold">One thing needs a look</p>
                    <p class="mt-2">{{ $bounce['message'] }} <span class="block">{{ $bounce['fix'] }}</span></p>
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <span class="rounded bg-zinc-900 px-3 py-2 font-medium text-white">Send again</span>
                        <span class="text-amber-800">The messages are saved below, so nothing is lost.</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- 2: the spam list, with the reason the form's page gives for each --}}
        <div class="flex flex-wrap-reverse items-center gap-x-14 gap-y-8">
            <div class="min-w-0 flex-[1_1_28rem] overflow-hidden rounded border border-zinc-900 bg-white">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 px-5 py-3.5">
                    <p class="font-semibold">Spam <span class="font-normal text-zinc-600">{{ $spamRows->count() }}</span></p>
                    <x-site.tag>Example data</x-site.tag>
                </div>
                <ul>
                    @foreach ($spamRows as $row)
                        <li class="border-b border-zinc-100 px-5 py-3.5 text-sm">
                            <p class="flex flex-wrap justify-between gap-x-4"><span class="font-medium">{{ $row['from'] }}</span><span class="min-w-0 truncate text-zinc-600 line-through">{{ $row['text'] }}</span></p>
                            <p class="mt-1 text-zinc-700">{{ $row['why'] }}</p>
                        </li>
                    @endforeach
                </ul>
                <p class="px-5 py-3 text-sm text-zinc-600">Press Not spam to move a message back to the inbox.</p>
            </div>
            <div class="flex min-w-0 flex-[1_1_24rem] flex-col gap-3">
                <h3 class="text-2xl leading-tight font-semibold tracking-tight sm:text-[1.75rem]">Spam never counts as a submission</h3>
                <p class="max-w-lg text-zinc-700">A hidden trap field, a rate limit and a link filter catch bots, with an invisible bot check and your own spam words when a form needs more. Caught spam waits in its own list, so nothing real gets lost. When a sales pitch gets through, press Block in the email: the next one from that company goes to spam too.</p>
            </div>
        </div>

        {{-- 3: the Monday summary, in the digest's own words --}}
        <div class="flex flex-wrap items-center gap-x-14 gap-y-8">
            <div class="flex min-w-0 flex-[1_1_24rem] flex-col gap-3">
                <h3 class="text-2xl leading-tight font-semibold tracking-tight sm:text-[1.75rem]">A Monday summary of every form</h3>
                <p class="max-w-lg text-zinc-700">It lists each site's messages for the past week and names any form that needs a look, including one that went quiet, so you find a broken form before your client does. Turn it off in Settings.</p>
            </div>
            <div class="min-w-0 flex-[1_1_28rem] overflow-hidden rounded border border-zinc-900 bg-white">
                <div class="flex flex-wrap items-start justify-between gap-3 border-b border-zinc-200 px-5 py-3.5">
                    <div class="min-w-0">
                        <p class="font-mono text-xs text-zinc-600">From: FormHaven · Monday</p>
                        <p class="mt-1 font-semibold">Your forms last week: {{ $digestWeek }} messages, {{ $digestLook->count() }} forms need a look</p>
                    </div>
                    <x-site.tag>Example data</x-site.tag>
                </div>
                <div class="border-b border-zinc-200 bg-amber-50 px-5 py-3.5 text-sm">
                    <p class="font-semibold text-amber-900">Needs a look</p>
                    <ul class="mt-1.5 flex flex-col gap-2 text-zinc-800">
                        @foreach ($digestLook as $form)
                            <li><strong class="font-semibold">{{ $form['name'] }}</strong> ({{ $form['host'] }}): {{ $form['problem']['message'] }} {{ $form['problem']['fix'] }}</li>
                        @endforeach
                    </ul>
                </div>
                <ul class="text-sm">
                    @foreach ($allForms as $form)
                        <li class="flex flex-wrap justify-between gap-x-4 gap-y-0.5 border-b border-zinc-100 px-5 py-3 last:border-b-0">
                            <span><span class="font-medium">{{ $form['host'] }}</span> <span class="text-zinc-600">{{ $form['name'] }}</span></span>
                            <span class="text-zinc-700">{{ $form['week'] }} {{ $form['week'] === 1 ? 'message' : 'messages' }} ({{ $form['before'] }} the week before){{ $form['spam'] ? ', '.$form['spam'].' spam caught' : '' }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        {{-- 4: the privacy text, generated by App\Support\PrivacyText for an example form. Pure CSS language switch. --}}
        <div class="flex flex-wrap-reverse items-center gap-x-14 gap-y-8">
            <div class="group/privacy min-w-0 flex-[1_1_28rem] overflow-hidden rounded border border-zinc-900 bg-white">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-200 px-5 py-3">
                    <p class="font-semibold">Privacy text for this form</p>
                    <div class="flex items-center gap-3">
                        <fieldset class="flex overflow-hidden rounded border border-zinc-300 font-mono text-xs">
                            <legend class="sr-only">Language</legend>
                            @foreach (\App\Support\PrivacyText::LANGUAGES as $lang)
                                <label class="cursor-pointer border-zinc-300 px-3 py-2 uppercase not-first:border-l has-[:checked]:bg-zinc-900 has-[:checked]:text-white has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-zinc-900 has-[:focus-visible]:ring-inset">
                                    <input type="radio" name="privacy-language" value="{{ $lang }}" id="privacy-{{ $lang }}" class="sr-only" @checked($loop->first)> {{ $lang }}
                                </label>
                            @endforeach
                        </fieldset>
                        <x-site.tag>Example data</x-site.tag>
                    </div>
                </div>
                @foreach ($privacy as $lang => $text)
                    <div lang="{{ $lang }}" @class([
                        'px-5 py-5 text-sm leading-relaxed text-zinc-700',
                        'group-has-[#privacy-nl:checked]/privacy:hidden group-has-[#privacy-de:checked]/privacy:hidden' => $lang === 'en',
                        'hidden group-has-[#privacy-nl:checked]/privacy:block' => $lang === 'nl',
                        'hidden group-has-[#privacy-de:checked]/privacy:block' => $lang === 'de',
                    ])>
                        <p class="font-semibold text-zinc-900">{{ $text['heading'] }}</p>
                        <p class="mt-2">{!! $highlight($lang, $text['paragraph']) !!}</p>
                    </div>
                @endforeach
                <p class="border-t border-zinc-200 px-5 py-3 text-sm text-zinc-600">Generated for an example form that keeps messages {{ $defaultDays }} days. Yours is filled in from your form's own settings.</p>
            </div>
            <div class="flex min-w-0 flex-[1_1_24rem] flex-col gap-3">
                <h3 class="text-2xl leading-tight font-semibold tracking-tight sm:text-[1.75rem]">The privacy text, written for you</h3>
                <p class="max-w-lg text-zinc-700">Each form gives you the paragraph for the site's privacy policy, with the real storage place and retention filled in. English, Dutch and German. Your agent can fetch it too.</p>
            </div>
        </div>
    </x-site.band>

    <x-site.band id="how-it-works" class="flex flex-col gap-9 py-20">
        <div class="flex flex-col gap-3">
            <x-site.eyebrow>After the visitor presses Send</x-site.eyebrow>
            <h2 class="text-3xl leading-tight font-semibold tracking-tight sm:text-[2.375rem]">Five steps, each one visible to you</h2>
        </div>
        <ol class="grid gap-px overflow-hidden rounded border border-zinc-900 bg-zinc-200 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
            @foreach ([
                ['Received', 'The form posts to its address', 'Plain HTML, or a fetch call that gets JSON back. Every input just needs a name.'],
                ['Spam check', 'Bots go to the spam list', 'A hidden trap field, a rate limit and a link filter. Spam never counts as a submission.'],
                ['Stored', 'Saved on EU servers', $provider.' in '.$place.'. It shows in your dashboard right away.'],
                ['Emailed', 'In your inbox, Reply goes to the visitor', 'If an inbox refuses it, you get an email naming the address and the reason.'],
                ['Deleted', 'Gone after the days you set', $defaultDays.' by default, anything from '.$minDays.' to '.$maxDays.' per form. Older ones are deleted every night.'],
            ] as $i => [$tag, $heading, $text])
                <li class="flex flex-col gap-2 bg-white p-6">
                    <span class="font-mono text-xs text-zinc-600 uppercase">{{ sprintf('%02d', $i + 1) }} {{ $tag }}</span>
                    <strong class="text-[1.0625rem] font-semibold">{{ $heading }}</strong>
                    <span class="text-[0.9375rem] text-zinc-700">{{ $text }}</span>
                </li>
            @endforeach
        </ol>

        <div id="demo" class="flex scroll-mt-6 flex-wrap overflow-hidden rounded border border-zinc-900 bg-white">
            <div class="flex min-w-0 flex-[1_1_22rem] flex-col gap-3.5 p-6 sm:p-8">
                <h3 class="text-2xl font-semibold tracking-tight">Try it: send this form</h3>
                <p class="max-w-md text-[0.9375rem] text-zinc-700">It posts to FormHaven's demo address and runs the same checks a real form does. The log shows each step your visitor's message goes through. The demo stores and emails nothing.</p>
                <form id="demo-form" action="{{ route('demo') }}" method="POST" class="mt-1 flex max-w-md flex-col gap-3.5">
                    <label class="flex flex-col gap-1.5 text-sm font-medium">Your email
                        <input type="email" name="email" placeholder="you@agency.eu" class="rounded border border-zinc-400 bg-white px-3.5 py-3 text-base font-normal">
                    </label>
                    <label class="flex flex-col gap-1.5 text-sm font-medium">Message
                        <textarea name="message" rows="3" placeholder="Testing FormHaven" class="rounded border border-zinc-400 bg-white px-3.5 py-3 text-base font-normal"></textarea>
                    </label>
                    <input type="text" name="_gotcha" hidden tabindex="-1" autocomplete="off">
                    <button type="submit" class="self-start rounded bg-zinc-900 px-5.5 py-3.5 font-medium text-white hover:bg-zinc-700">Send test message</button>
                </form>
            </div>
            <div class="min-w-0 flex-[1_1_26rem] self-stretch bg-zinc-900 p-6 font-mono text-[0.8125rem] leading-loose text-zinc-200 sm:p-8" aria-live="polite">
                <p class="mb-2.5 text-[0.6875rem] tracking-[0.08em] text-zinc-400 uppercase">What happened to your message</p>
                <ol id="demo-log" class="grid gap-1">
                    <li class="text-zinc-400">Waiting for you to press Send.</li>
                </ol>
            </div>
        </div>
    </x-site.band>

    <x-site.band class="flex flex-col gap-6 py-20">
        <div class="flex flex-col gap-3">
            <x-site.eyebrow>Data location</x-site.eyebrow>
            <h2 class="text-3xl leading-tight font-semibold tracking-tight sm:text-[2.375rem]">Where the submissions live</h2>
            <p class="max-w-3xl text-zinc-700">Your client asks where their visitors' messages are stored. Here is the answer for us and for the form backends you are likely comparing, taken from each one's own pages.</p>
        </div>
        <div class="overflow-x-auto rounded border border-zinc-900">
            <table class="w-full min-w-[44rem] text-left text-sm">
                <thead class="bg-zinc-50 font-mono text-[0.6875rem] tracking-[0.1em] text-zinc-600 uppercase">
                    <tr><th scope="col" class="border-b border-zinc-900 px-4 py-3 font-medium">Service</th><th scope="col" class="border-b border-zinc-900 px-4 py-3 font-medium">Where submissions are stored</th><th scope="col" class="border-b border-zinc-900 px-4 py-3 font-medium">Company based in</th><th scope="col" class="border-b border-zinc-900 px-4 py-3 font-medium">Source</th></tr>
                </thead>
                <tbody class="[&>tr+tr]:border-t [&>tr+tr]:border-zinc-200">
                    <tr class="bg-zinc-100"><td class="px-4 py-3.5 font-semibold">FormHaven</td><td class="px-4 py-3.5 font-semibold">Hetzner, Germany</td><td class="px-4 py-3.5">Netherlands</td><td class="px-4 py-3.5"><a href="{{ route('legal', 'subprocessors') }}" class="underline">Subprocessors</a></td></tr>
                    <tr><td class="px-4 py-3.5 font-medium">Formspree</td><td class="px-4 py-3.5">United States</td><td class="px-4 py-3.5">United States</td><td class="px-4 py-3.5"><x-ext-link class="underline" href="https://formspree.io/legal/privacy-policy">Privacy policy</x-ext-link></td></tr>
                    <tr><td class="px-4 py-3.5 font-medium">Formspark</td><td class="px-4 py-3.5">AWS and Hetzner, Ireland and Germany</td><td class="px-4 py-3.5">Belgium</td><td class="px-4 py-3.5"><x-ext-link class="underline" href="https://formspark.io/legal/dpa/">DPA</x-ext-link></td></tr>
                    <tr><td class="px-4 py-3.5 font-medium">Web3Forms</td><td class="px-4 py-3.5">Several providers, Hetzner among them</td><td class="px-4 py-3.5">India</td><td class="px-4 py-3.5"><x-ext-link class="underline" href="https://web3forms.com/privacy">Privacy policy</x-ext-link></td></tr>
                    <tr><td class="px-4 py-3.5 font-medium">Basin</td><td class="px-4 py-3.5">DigitalOcean, Canada (backups in Germany)</td><td class="px-4 py-3.5">Canada</td><td class="px-4 py-3.5"><x-ext-link class="underline" href="https://usebasin.com/gdpr">GDPR page</x-ext-link></td></tr>
                    <tr><td class="px-4 py-3.5 font-medium">nForms</td><td class="px-4 py-3.5">Cloudflare D1, EU jurisdiction</td><td class="px-4 py-3.5">Germany</td><td class="px-4 py-3.5"><x-ext-link class="underline" href="https://nforms.eu/privacy">Privacy policy</x-ext-link></td></tr>
                    <tr><td class="px-4 py-3.5 font-medium">Formcarry</td><td class="px-4 py-3.5">DigitalOcean, Frankfurt, Germany. File attachments at AWS, listed as United States</td><td class="px-4 py-3.5">United States</td><td class="px-4 py-3.5"><x-ext-link class="underline" href="https://formcarry.com/legal/data-processing-agreement">DPA</x-ext-link></td></tr>
                    <tr><td class="px-4 py-3.5 font-medium">Forminit (was Getform)</td><td class="px-4 py-3.5">AWS, Ireland</td><td class="px-4 py-3.5">United Kingdom</td><td class="px-4 py-3.5"><x-ext-link class="underline" href="https://forminit.com/">Home page</x-ext-link></td></tr>
                </tbody>
            </table>
        </div>
        <p class="text-sm text-zinc-600">Checked on 27 October 2026, Formspark on 3 November 2026, nForms and Formcarry on 12 November 2026. Spot something out of date? Email <a href="mailto:{{ config('legal.support_email') }}" class="underline">{{ config('legal.support_email') }}</a> and we correct it.</p>
    </x-site.band>

    <x-site.band class="flex flex-wrap gap-x-14 gap-y-8 py-20">
        <div class="flex min-w-0 flex-[1_1_22rem] flex-col gap-3.5">
            <x-site.eyebrow>Security and privacy</x-site.eyebrow>
            <h2 class="text-3xl leading-tight font-semibold tracking-tight text-balance sm:text-[2.375rem]">The answers your client's privacy file asks for</h2>
            <p class="max-w-md text-zinc-700">Named providers and places, and paperwork you can forward without rewriting it.</p>
            <a href="{{ route('legal', 'dpa') }}" class="self-start py-3 font-medium underline">Read the data processing agreement</a>
        </div>
        <div class="min-w-0 flex-[2_1_34rem] overflow-hidden rounded border border-zinc-900">
            <table class="w-full text-left text-[0.9375rem]">
                <caption class="sr-only">Where messages go and who handles them</caption>
                <tbody class="[&>tr+tr]:border-t [&>tr+tr]:border-zinc-200">
                    @foreach ([
                        ['Where messages are stored', e($provider.', '.$place)],
                        ['How long they are kept', e($defaultDays.' days by default, '.$minDays.' to '.$maxDays.' per form. Older ones are deleted every night.')],
                        ['Agreement', 'A <a href="'.e(route('legal', 'dpa')).'" class="underline">data processing agreement</a> on every account'],
                        ['Other companies involved', 'A public <a href="'.e(route('legal', 'subprocessors')).'" class="underline">subprocessor list</a>'],
                        ['Run by', e(config('legal.entity').', '.config('legal.country.en').', KvK '.config('legal.kvk'))],
                    ] as [$label, $value])
                        <tr>
                            <th scope="row" class="w-[38%] bg-zinc-50 px-5 py-4 align-top font-mono text-xs font-medium tracking-[0.08em] text-zinc-700 uppercase">{{ $label }}</th>
                            <td class="px-5 py-4 align-top">{!! $value !!}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-site.band>

    <x-site.band tone="tint" class="flex flex-wrap gap-x-14 gap-y-8 py-20">
        <div class="flex min-w-0 flex-[1_1_22rem] flex-col gap-4">
            <x-site.eyebrow>Price</x-site.eyebrow>
            <h2 class="text-3xl leading-tight font-semibold tracking-tight text-balance sm:text-[2.375rem]">{{ $one }} per form a month, {{ $lower }} from the {{ $from }}</h2>
            <p class="max-w-lg text-zinc-700">Every feature on every form. Add a form and the bill follows from that day; delete one and it goes down.</p>
            <div class="flex flex-wrap items-center gap-3">
                <a href="{{ route('register') }}" class="rounded bg-zinc-900 px-5.5 py-3.5 font-medium text-white hover:bg-zinc-700">Start {{ $trial }}-day free trial</a>
                <a href="{{ route('pricing') }}" class="px-1 py-3 font-medium underline">See pricing</a>
            </div>
        </div>
        <div class="min-w-0 flex-[1_1_28rem] self-start overflow-x-auto rounded border border-zinc-900 bg-white">
            <table class="w-full text-left tabular-nums">
                <caption class="sr-only">What your bill comes to</caption>
                <thead class="bg-zinc-50 font-mono text-[0.6875rem] tracking-[0.1em] text-zinc-600 uppercase">
                    <tr><th scope="col" class="border-b border-zinc-900 px-3 sm:px-4.5 py-3 font-medium">Forms</th><th scope="col" class="border-b border-zinc-900 px-3 sm:px-4.5 py-3 font-medium">Submissions a month</th><th scope="col" class="border-b border-zinc-900 px-3 sm:px-4.5 py-3 text-right font-medium">You pay</th></tr>
                </thead>
                <tbody>
                    @foreach ($examples as $forms)
                        <tr class="border-zinc-200 not-first:border-t">
                            <td class="px-3 sm:px-4.5 py-3.5 font-semibold whitespace-nowrap">{{ $forms === $perForm['min_forms'] ? '1 to '.$forms : $forms }}</td>
                            <td class="px-3 sm:px-4.5 py-3.5 text-zinc-700">{{ number_format($forms * $perForm['submissions']) }}</td>
                            <td class="px-3 sm:px-4.5 py-3.5 text-right whitespace-nowrap"><span class="block font-semibold">{{ $bill::format($bill::monthly($forms)) }} a month</span><span class="block text-sm text-zinc-600">or {{ $bill::format($bill::yearly($forms)) }} a year</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-site.band>

    <x-site.band class="flex flex-wrap gap-x-10 gap-y-6 py-20">
        <h2 class="flex-[1_1_15rem] text-3xl leading-tight font-semibold tracking-tight sm:text-[2.375rem]">Questions</h2>
        <dl class="flex min-w-0 flex-[3_1_32rem] flex-col">
            @foreach ($faq as [$question, $answer])
                <div @class(['py-5', 'border-t border-zinc-900' => $loop->first, 'border-t border-zinc-200' => ! $loop->first, 'border-b border-zinc-200' => $loop->last])>
                    <dt class="font-semibold">{{ $question }}</dt>
                    <dd class="mt-1.5 max-w-3xl text-zinc-700">{!! $answer !!}</dd>
                </div>
            @endforeach
        </dl>
    </x-site.band>

    <x-site.cta-band title="Get the next site's form working today" />

    <script>
        (() => {
            const form = document.getElementById('demo-form');
            const log = document.getElementById('demo-log');
            const line = (tag, text, muted) => {
                const li = document.createElement('li');
                li.className = 'flex gap-3' + (muted ? ' text-zinc-400' : '');
                const t = document.createElement('span');
                t.className = 'min-w-[5.5rem] text-white';
                t.textContent = tag ? '[' + tag + ']' : '';
                const s = document.createElement('span');
                s.textContent = text;
                li.append(t, s);
                return li;
            };
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                log.replaceChildren(line('', 'Sending...', true));
                try {
                    const res = await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json' }, body: new FormData(form) });
                    const body = await res.json();
                    log.replaceChildren(...(body.ok ? body.steps.map((s) => line(s.tag, s.text)) : [line('', body.message || 'That did not work. Try again in a minute.', true)]));
                } catch {
                    log.replaceChildren(line('', 'Could not reach the demo. Try again in a minute.', true));
                }
            });
        })();
    </script>
</x-layouts.site>
