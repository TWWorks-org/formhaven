{{-- Article schema for a page with an article body. Author and publisher are the organisation, never a person:
     the business is run anonymously. Dates are optional and only ever passed from a real date in the content:
     @include('partials.schema-article', ['headline' => $page['h1'], 'description' => $page['description'], 'url' => route('guides.show', $slug)])
     Optional: 'datePublished' / 'dateModified' (ISO 8601) and 'image' (defaults to the og:image seo-head uses). --}}
{{-- Built in the raw PHP block on purpose: outside it, Blade compiles the at-sign context key as its own directive. --}}
@php
    $organisation = ['@type' => 'Organization', 'name' => config('app.name'), 'url' => config('app.url')];
    $schema = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $headline,
        'description' => $description,
        'url' => $url,
        'image' => $image ?? asset('og-default.png'),
        'datePublished' => $datePublished ?? null,
        'dateModified' => $dateModified ?? null,
        'author' => $organisation,
        'publisher' => $organisation + ['logo' => ['@type' => 'ImageObject', 'url' => asset('apple-touch-icon.png')]],
        'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
    ], fn ($value) => $value !== null);
@endphp
<script type="application/ld+json">
{!! json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}
</script>
