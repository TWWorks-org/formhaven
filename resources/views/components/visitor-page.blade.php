@props(['title', 'back' => null, 'backLabel' => 'Back to'])
{{-- The pages a site's visitors see after posting a form. Plain inline CSS: they must render without our app bundle. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }}</title>
    <style>
        body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: #fafafa; color: #171717; min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 24px; padding: 48px 16px; box-sizing: border-box; }
        main { width: 100%; max-width: 30rem; box-sizing: border-box; background: #fff; border: 1px solid #e5e5e5; border-radius: 16px; padding: 40px 32px; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 14px; }
        h1 { margin: 0; font-size: 24px; font-weight: 600; }
        p { margin: 0; color: #404040; line-height: 1.6; }
        .back { margin-top: 8px; background: #171717; color: #fff; padding: 13px 20px; border-radius: 10px; font-weight: 500; text-decoration: none; }
        .credit { font-size: 13px; color: #525252; }
        .credit a { color: inherit; }
    </style>
</head>
<body>
    <main>
        {{ $icon ?? '' }}
        <h1>{{ $heading }}</h1>
        {{ $slot }}
        @if ($back)
            <a class="back" href="{{ $back }}">{{ $backLabel }} {{ parse_url($back, PHP_URL_HOST) }}</a>
        @endif
    </main>
@if (\App\Support\Mode::hosted())
    <p class="credit">Stored in the EU by <a href="{{ route('home') }}">FormHaven</a></p>
@else
    <p class="credit">{{ \App\Support\Operator::storedInEu() ? 'Stored in the EU by FormHaven' : 'Received by FormHaven' }}</p>
@endif
</body>
</html>
