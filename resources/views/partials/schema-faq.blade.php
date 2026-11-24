{{-- FAQPage schema. Pass the SAME array the page renders its visible FAQ from, as [question, answer] pairs
     of plain text, so the two cannot drift (StructuredDataTest compares them on every page):
     @include('partials.schema-faq', ['questions' => $page['faq']]) --}}
{{-- Built in the raw PHP block on purpose: outside it, Blade compiles the at-sign context key as its own directive. --}}
@php
    $schema = [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => array_map(fn (array $faq) => [
            '@type' => 'Question',
            'name' => $faq[0],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq[1]],
        ], array_values($questions)),
    ];
@endphp
<script type="application/ld+json">
{!! json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}
</script>
