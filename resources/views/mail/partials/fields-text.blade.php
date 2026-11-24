{{-- Plain-text twin of fields.blade.php: printed raw on purpose, since HTML escaping would turn a link's & into &amp;. A long answer goes under its label. --}}
@php
    $label = fn (string $key) => \App\Models\Submission::fieldLabel($key);
    $filled = array_filter($fields, fn ($v) => trim((string) $v) !== '');
    $empty = array_keys(array_diff_key($fields, $filled));
@endphp
@foreach ($filled as $key => $value)
@php($value = trim(str_replace(["\r\n", "\r"], "\n", (string) $value)))
@if (str_contains($value, "\n") || mb_strlen($value) > 80)
{!! $label((string) $key) !!}:
{!! $value !!}

@else
{!! $label((string) $key) !!}: {!! $value !!}
@endif
@endforeach
@if ($empty)
Left empty: {!! implode(', ', array_map(fn ($k) => $label((string) $k), $empty)) !!}
@endif
