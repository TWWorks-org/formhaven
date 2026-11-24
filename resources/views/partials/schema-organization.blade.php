{{-- Organization schema: ships on every page via seo-head. No founder or social profiles on
     purpose: the business is run anonymously and has none to list. Validate at
     https://validator.schema.org --}}
{{-- The array is built in the raw PHP block below on purpose: outside it, Blade compiles the at-sign context key as its own context directive, even inside an echo, and the JSON breaks. --}}
@php
    $schema = [
    '@context' => 'https://schema.org',
    '@type' => 'Organization',
    'name' => config('app.name'),
    'legalName' => config('legal.entity'),
    'url' => config('app.url'),
    'logo' => asset('apple-touch-icon.png'),
    'description' => 'FormHaven is the EU form backend an AI agent can set up end to end: submissions are stored in '.config('legal.hosting.place.en').', spam filtered and emailed to the site owner, with a DPA and the privacy text for the site.',
    'foundingDate' => '2026',
    'contactPoint' => ['@type' => 'ContactPoint', 'email' => config('legal.support_email'), 'contactType' => 'customer support'],
];
@endphp
<script type="application/ld+json">
{!! json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>
