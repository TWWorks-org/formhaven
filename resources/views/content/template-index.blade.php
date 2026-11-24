@php($trial = config('billing.trial_days'))
<x-layouts.site title="HTML form templates: FormHaven" description="Copy-paste HTML form templates that work without a server: contact forms and EU-law forms such as the withdrawal form. Submissions stored in the EU.">
    <x-site.hero
        title="HTML form templates that work without a server"
        subtitle="Copy one, put your FormHaven address in the action, and every submission is stored in the EU and emailed to you. Each template is plain HTML that takes your site's own styling."
        :cta="['href' => route('register'), 'label' => 'Get your form address', 'note' => $trial.' days free, no card needed.']" />

    <section class="grid gap-4 pb-14 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ($templates as $slug => $template)
            <a href="{{ route('templates.show', $slug) }}" class="flex flex-col gap-2 rounded-xl border border-zinc-200 p-6 hover:border-zinc-400">
                <span class="text-lg font-semibold">{{ $template['name'] }}</span>
                <span class="text-zinc-700">{{ $template['card'] }}</span>
                <span class="mt-2 text-sm text-zinc-600">{{ count($template['fields']) }} fields, {{ count($template['variations']) }} variations</span>
            </a>
        @endforeach
    </section>

    <x-site.cta-band title="Get your form address in a minute" />
</x-layouts.site>
