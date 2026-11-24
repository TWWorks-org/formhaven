@php($trial = config('billing.trial_days'))
<x-layouts.site title="Contact form setup guides: FormHaven" description="Step-by-step guides to a working contact form on Astro, Hugo, Next.js, SvelteKit, Nuxt, static hosts and AI-built sites, plus webhooks, GDPR text and moving from Formspree.">
    <x-site.hero
        title="Contact form guides for every stack"
        subtitle="Pick the stack your site is built with and copy the code. Every message is stored in the EU and emailed to you, with no server to run."
        :cta="['href' => route('register'), 'label' => 'Get your form address', 'note' => $trial.' days free, no card needed.']" />

    @foreach ($groups as $heading => $guides)
        <section class="pb-12">
            <h2 class="text-2xl font-semibold tracking-tight">{{ $heading }}</h2>
            <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                @foreach ($guides as $slug => $guide)
                    <a href="{{ route('guides.show', $slug) }}" class="flex flex-col gap-2 rounded-xl border border-zinc-200 p-6 hover:border-zinc-400">
                        <span class="text-lg font-semibold">{{ $guide['name'] }}</span>
                        <span class="text-zinc-700">{{ $guide['card'] }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    @endforeach

    <x-site.cta-band title="Get your form address in a minute" />
</x-layouts.site>
