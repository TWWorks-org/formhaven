{{-- Laravel's notification email in FormHaven's words: no "Hello!" or "Regards", and a short fallback link under the button. --}}
<x-mail::message>
@if (! empty($greeting))
# {{ $greeting }}
@endif

@foreach ($introLines as $line)
{{ $line }}

@endforeach

@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

@foreach ($outroLines as $line)
{{ $line }}

@endforeach

@if (! empty($salutation))
{{ $salutation }}
@endif

@isset($actionText)
<x-slot:subcopy>
Button not working? Open this link: <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
