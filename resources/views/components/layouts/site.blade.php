{{-- lang: the page's language (navigation and footer stay English). alternates: hreflang => URL of the
     same page in each language, the page itself included, plus 'x-default'; empty for a page in one language. --}}
@props(['title' => null, 'description' => null, 'lang' => 'en', 'alternates' => []])
<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
{{-- A self-hosted install keeps only the pages its owner and visitors need (claim, 404): no SEO head, no web font from another server. --}}@if (\App\Support\Mode::hosted())
    @include('partials.seo-head', ['title' => $title, 'description' => $description])
    @foreach ($alternates as $hreflang => $href)
        <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}">
    @endforeach
    {{-- A page group's own JSON-LD (Article, FAQPage, BreadcrumbList), from <x-slot:jsonLd> in its template. --}}
    {{ $jsonLd ?? '' }}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600|jetbrains-mono:400,500" rel="stylesheet" />
@else
    <title>{{ $title ?? config('app.name') }}</title>
    <meta name="robots" content="noindex">
@endif
    @vite(['resources/css/app.css'])
    @include('partials.analytics')
</head>
<body class="flex min-h-screen flex-col bg-white text-zinc-900 antialiased">
    <header class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 border-b border-zinc-200 px-4 py-4 sm:px-6 xl:px-8">
        <a href="{{ route('home') }}" class="flex items-center gap-2.5 text-[1.0625rem] font-semibold"><span aria-hidden="true" class="inline-block size-3.5 bg-zinc-900"></span>FormHaven</a>
        <nav class="flex flex-wrap items-center justify-end gap-x-6 gap-y-2 text-sm">
@if (\App\Support\Mode::hosted())
            <a href="{{ route('home') }}#how-it-works" class="hidden hover:underline sm:inline">How it works</a>
            <a href="{{ route('home') }}#agents" class="hidden hover:underline sm:inline">For AI agents</a>
            <a href="{{ route('pricing') }}" class="hover:underline">Pricing</a>
            @auth
                <a href="{{ route('forms.index') }}" class="font-medium underline">Your forms</a>
            @else
                <a href="{{ route('login') }}" class="hover:underline">Log in</a>
                <a href="{{ route('register') }}" class="rounded bg-zinc-900 px-4 py-2.5 font-medium text-white hover:bg-zinc-700">Start free trial</a>
            @endauth
@else
            @auth
                <a href="{{ route('forms.index') }}" class="font-medium underline">Your forms</a>
            @else
                <a href="{{ route('login') }}" class="hover:underline">Log in</a>
            @endauth
@endif
        </nav>
    </header>

    {{-- The page frame: two guide lines inside the gutters run the whole page. Full-width bands
         inside <main> pull out to these lines with -mx-6 sm:-mx-8 xl:-mx-12, matching main's padding. --}}
    <div class="mx-4 flex flex-1 flex-col border-x border-zinc-200 sm:mx-6 xl:mx-8">
        <main class="flex-1 px-6 pb-20 sm:px-8 xl:px-12">
            {{ $slot }}
        </main>
    </div>

    <footer class="border-t border-zinc-200 px-4 pt-12 pb-8 text-sm sm:px-6 xl:px-8">
{{-- The hosted footer: product, guides, comparisons, legal pages, the maker list and the operator line. --}}@if (\App\Support\Mode::hosted())
        <div class="max-w-md">
            <p class="flex items-center gap-2.5 font-semibold"><span aria-hidden="true" class="inline-block size-3 bg-zinc-900"></span>FormHaven</p>
            <p class="mt-2 text-zinc-600">The EU form backend your AI agent sets up. Messages stored in Germany, filtered for spam and emailed to you.</p>
        </div>
        <div class="mt-10 grid grid-cols-2 gap-10 lg:grid-cols-[1fr_3fr_1fr_1fr]">
            <div>
                <p class="font-mono text-xs font-medium tracking-[0.12em] text-zinc-600 uppercase">Product</p>
                <ul class="mt-3 grid gap-2 text-zinc-700">
                    <li><a href="{{ route('home') }}#how-it-works" class="hover:underline">How it works</a></li>
                    <li><a href="{{ route('pricing') }}" class="hover:underline">Pricing</a></li>
                    <li><a href="{{ route('templates.index') }}" class="hover:underline">Form templates</a></li>
                    <li><a href="{{ route('guides.index') }}" class="hover:underline">Setup guides</a></li>
                    <li><a href="{{ route('home') }}#agents" class="hover:underline">For AI agents</a></li>
                    <li><a href="{{ route('llms') }}" class="hover:underline">llms.txt</a></li>
                </ul>
            </div>
            <div class="order-last col-span-2 lg:order-none lg:col-span-1">
                <p class="font-mono text-xs font-medium tracking-[0.12em] text-zinc-600 uppercase"><a href="{{ route('guides.index') }}" class="hover:underline">Guides</a></p>
                <ul class="mt-3 gap-x-8 text-zinc-700 sm:columns-2 2xl:columns-3">
                    @foreach (\App\Http\Controllers\ContentController::guides() as $guideSlug => $guide)
                        <li class="mb-2 break-inside-avoid"><a href="{{ route('guides.show', $guideSlug) }}" class="hover:underline">{{ $guide['name'] }}</a></li>
                    @endforeach
                </ul>
            </div>
            <div>
                <p class="font-mono text-xs font-medium tracking-[0.12em] text-zinc-600 uppercase">Compare</p>
                <ul class="mt-3 grid gap-2 text-zinc-700">
                    @foreach (\App\Http\Controllers\ContentController::alternatives() as $altSlug => $alt)
                        <li><a href="{{ route('alternatives.show', $altSlug) }}" class="hover:underline">FormHaven vs {{ $alt['name'] }}</a></li>
                    @endforeach
                </ul>
            </div>
            <div>
                <p class="font-mono text-xs font-medium tracking-[0.12em] text-zinc-600 uppercase">Account</p>
                <ul class="mt-3 grid gap-2 text-zinc-700">
                    <li><a href="{{ route('register') }}" class="hover:underline">Start free trial</a></li>
                    <li><a href="{{ route('login') }}" class="hover:underline">Log in</a></li>
                </ul>
                <p class="mt-8 font-mono text-xs font-medium tracking-[0.12em] text-zinc-600 uppercase">Legal</p>
                <ul class="mt-3 grid gap-2 text-zinc-700">
                    <li><a href="{{ route('legal', 'privacy') }}" class="hover:underline">Privacy</a></li>
                    <li><a href="{{ route('legal', 'terms') }}" class="hover:underline">Terms</a></li>
                    <li><a href="{{ route('legal', 'dpa') }}" class="hover:underline">Data processing agreement</a></li>
                    <li><a href="{{ route('legal', 'subprocessors') }}" class="hover:underline">Subprocessors</a></li>
                    <li><a href="{{ route('report') }}" class="hover:underline">Report a form</a></li>
                </ul>
            </div>
        </div>
        {{-- More from the same maker: followed cross-links to the sibling products,
             the same block as on those sites. Branded anchors, server-rendered,
             no nofollow. List only sites that answer 200. --}}
        <div class="mt-10 border-t border-zinc-200 pt-8">
            <p class="font-mono text-xs font-medium tracking-[0.12em] text-zinc-600 uppercase">More from the same maker</p>
            <ul class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-zinc-700">
                @foreach ([
                    ['name' => 'QueryScope', 'href' => 'https://queryscope.dev', 'blurb' => 'The AI SEO agent that lives in your IDE'],
                    ['name' => 'FrictionScope', 'href' => 'https://frictionscope.dev', 'blurb' => 'Page friction in your editor'],
                    ['name' => 'MCP Hunter', 'href' => 'https://mcp-hunter.com', 'blurb' => 'Weekly launch board for MCP servers'],
                    ['name' => 'AppScreenshotStudio', 'href' => 'https://appscreenshotstudio.com', 'blurb' => 'App Store screenshot generator'],
                    ['name' => 'BookIllustrationAI', 'href' => 'https://bookillustrationai.com', 'blurb' => 'AI coloring book maker for Amazon KDP'],
                    ['name' => 'SecureStartKit', 'href' => 'https://securestartkit.com', 'blurb' => 'Security-first SaaS starter kit'],
                    ['name' => 'Sportschoolradar', 'href' => 'https://sportschoolradar.com', 'blurb' => 'Dutch gyms compared on their real monthly price'],
                    ['name' => 'DidMyFormSend', 'href' => 'https://didmyformsend.com', 'blurb' => 'Tells you when a contact form handler stops getting mail out'],
                    ['name' => 'MyCVCraft', 'href' => 'https://mycvcraft.com', 'blurb' => 'AI CV optimizer, tuned per job'],
                    ['name' => 'StyleMyFade', 'href' => 'https://stylemyfade.com', 'blurb' => 'AI hairstyle advisor for men'],
                ] as $sibling)
                    <li><a href="{{ $sibling['href'] }}" target="_blank" rel="noopener" title="{{ $sibling['blurb'] }}" class="hover:underline">{{ $sibling['name'] }}</a></li>
                @endforeach
            </ul>
        </div>
        {{-- Required on every page by art. 3:15d BW. The BTW number goes on invoices only. --}}
@else
        <p class="flex items-center gap-2.5 font-semibold"><span aria-hidden="true" class="inline-block size-3 bg-zinc-900"></span>FormHaven</p>
        <p class="mt-2 text-zinc-600">This install's form backend. <a href="{{ route('llms') }}" class="hover:underline">Guide for AI agents (llms.txt)</a></p>
@endif
@if (\App\Support\Mode::hosted())
        <p class="mt-10 text-zinc-600">© {{ date('Y') }} {{ config('legal.entity') }} · KvK {{ config('legal.kvk') }}@if (config('legal.address')) · {{ config('legal.address') }}@endif · <a href="mailto:{{ config('legal.email') }}" class="hover:underline">{{ config('legal.email') }}</a> · Hosted in the EU.</p>
@endif
    </footer>
    <script>
        // Copy buttons next to code: [data-copy] copies the [data-copy-source] in the same block.
        // A page in another language sets data-copied and data-copy-failed on the button; English is the default.
        document.addEventListener('click', async (e) => {
            const button = e.target.closest('[data-copy]');
            const source = button?.parentElement?.parentElement?.querySelector('[data-copy-source]');
            if (!source) return;
            const label = button.dataset.label ??= button.textContent;
            try { await navigator.clipboard.writeText(source.textContent); button.textContent = button.dataset.copied ?? 'Copied'; } catch { button.textContent = button.dataset.copyFailed ?? 'Select and copy'; }
            setTimeout(() => { button.textContent = label; }, 2000);
        });
    </script>
</body>
</html>
