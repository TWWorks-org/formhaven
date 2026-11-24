{{--
    SEO head block. Include inside <head> of your layout, REPLACING the
    starter kit's own <title> / meta description lines (no duplicates):

        @include('partials.seo-head')

    Per page, pass what differs (all optional):

        @include('partials.seo-head', [
            'title'       => 'Page Title | Formhaven',          // under 60 chars
            'description' => '140-160 char description.',
            'ogImage'     => asset('og-images/this-page.png'),
            'ogType'      => 'article',                      // default: website
            'canonical'   => url('/custom-canonical'),
        ])
--}}
@php
    $seoTitle = $title ?? config('app.name');
    $seoDescription = $description ?? 'Default site description, 140-160 chars. Set the real one in partials/seo-head.blade.php.';
    $seoCanonical = $canonical ?? url()->current();
    $seoOgImage = $ogImage ?? asset('og-default.png');
    $seoOgType = $ogType ?? 'website';
@endphp
<title>{{ $seoTitle }}</title>
<meta name="description" content="{{ $seoDescription }}">
<link rel="canonical" href="{{ $seoCanonical }}">

{{-- Open Graph (Facebook, LinkedIn, Slack previews) --}}
<meta property="og:type" content="{{ $seoOgType }}">
<meta property="og:url" content="{{ $seoCanonical }}">
<meta property="og:title" content="{{ $seoTitle }}">
<meta property="og:description" content="{{ $seoDescription }}">
<meta property="og:image" content="{{ $seoOgImage }}">
<meta property="og:site_name" content="{{ config('app.name') }}">

{{-- Twitter / X card --}}
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $seoTitle }}">
<meta name="twitter:description" content="{{ $seoDescription }}">
<meta name="twitter:image" content="{{ $seoOgImage }}">

{{-- Favicons: Google crops them to a circle, so keep a solid mark inside the central 80% and check it at 16px --}}
<link rel="icon" href="/favicon.ico" sizes="48x48">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

{{-- Organization schema on every page (entity signal for Google + AI engines) --}}
@include('partials.schema-organization')
@if (request()->routeIs('home'))
    @include('partials.schema-software-application')
@endif
