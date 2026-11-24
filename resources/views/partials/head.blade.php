<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>{{ $title ?? config('app.name') }}</title>
<meta name="robots" content="noindex">
<link rel="icon" href="/favicon.ico" sizes="48x48">
<link rel="icon" href="/icon.svg" type="image/svg+xml">

{{-- The web font comes from Bunny's CDN on the hosted service only: a self-hosted install calls no one else's server and uses the system font. --}}@if (\App\Support\Mode::hosted())
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
@endif

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
