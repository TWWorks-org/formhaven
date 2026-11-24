{{-- BreadcrumbList schema for a page under an index. $items are [name, url] pairs, Home first, each a page
     that answers 200, following the visible breadcrumb:
     @include('partials.schema-breadcrumbs', ['items' => [['Home', route('home')], ['Guides', route('guides.index')], [$page['name'], url()->current()]]]) --}}
{{-- Built in the raw PHP block on purpose: outside it, Blade compiles the at-sign context key as its own directive. --}}
@php
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => array_map(fn (array $crumb, int $i) => [
            '@type' => 'ListItem',
            'position' => $i + 1,
            'name' => $crumb[0],
            'item' => $crumb[1],
        ], array_values($items), array_keys(array_values($items))),
    ];
@endphp
<script type="application/ld+json">
{!! json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}
</script>
