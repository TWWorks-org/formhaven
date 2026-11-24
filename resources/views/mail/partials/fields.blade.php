{{--
    Answers as label-above-value blocks, so a label can never touch its answer at any width.
    $fields: field name => answer, both typed by someone else, so both are escaped.
    Shared by the Markdown emails (through <x-mail::fields>) and the hand-built client emails,
    hence inline styles only. It must never print a blank line: inside a Markdown email a blank
    line ends the HTML block, and a visitor's "[text](url)" after it would turn into a link.
    That is why every line break in an answer becomes <br> and the markup has no empty lines.
--}}
@php
    $label = fn (string $key) => \App\Models\Submission::fieldLabel($key);
    $answer = fn (string $text) => str_replace("\n", '<br>', e(str_replace(["\r\n", "\r"], "\n", trim($text))));
    $filled = array_filter($fields, fn ($v) => trim((string) $v) !== '');
    $empty = array_keys(array_diff_key($fields, $filled));
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse; width: 100%; margin: 0 0 20px;">
@foreach ($filled as $key => $value)
@php($long = str_contains((string) $value, "\n") || mb_strlen((string) $value) > 80)
<tr><td style="padding: 12px 0; border-bottom: 1px solid #f0f0f2;">
<div style="font-size: 12px; line-height: 1.4; letter-spacing: 0.06em; text-transform: uppercase; font-weight: 600; color: #71717a; margin: 0 0 4px;">{{ $label((string) $key) }}</div>
@if ($long)
<div style="font-size: 16px; line-height: 1.6; color: #18181b; background-color: #f8f8f9; border-left: 3px solid #d4d4d8; padding: 10px 14px; overflow-wrap: anywhere; word-break: break-word;">{!! $answer((string) $value) !!}</div>
@else
<div style="font-size: 16px; line-height: 1.6; color: #18181b; overflow-wrap: anywhere; word-break: break-word;">{!! $answer((string) $value) !!}</div>
@endif
</td></tr>
@endforeach
@if ($empty)
<tr><td style="padding: 12px 0 0; font-size: 13px; line-height: 1.5; color: #a1a1aa;">Left empty: {{ implode(', ', array_map(fn ($k) => $label((string) $k), $empty)) }}</td></tr>
@endif
</table>
