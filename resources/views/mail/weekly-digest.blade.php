{{-- Form names and site hosts are typed by a person, so they go through $md and never become a link or image. Lists, not a table: tables break in mail apps on a phone. --}}
@php($md = fn ($text) => \App\Support\MarkdownText::escape(str_replace(["\r\n", "\n", '|'], [' ', ' ', '/'], (string) $text)))
@php($messages = fn (int $n) => $n.' '.($n === 1 ? 'message' : 'messages'))
@php($line = fn ($form) => $messages($form->digest_week).($form->digest_before !== null ? ' ('.$form->digest_before.' the week before)' : ' (keeps messages under '.\App\Mail\WeeklyDigest::COMPARE_DAYS.' days, so no comparison)').($form->digest_spam ? ', '.$form->digest_spam.' spam caught' : ''))
<x-mail::message>
# Your forms last week

{{ $messages($week).' came in across your forms in the last 7 days'.($before !== null ? ', against '.$before.' the week before' : '').'.' }} {{ $spam ? $spam.' spam '.($spam === 1 ? 'message was' : 'messages were').' kept out of your inbox.' : '' }}

@if ($problems !== [])
## Needs a look

@foreach ($problems as $item)
- **{{ $md($item['form']->name) }}**{{ $item['form']->siteHost() ? ' ('.$md($item['form']->siteHost()).')' : '' }}: {{ $item['problem']['message'] }} {{ $item['problem']['fix'] }}
@endforeach

<x-mail::button :url="route('forms.index')">
Open your forms
</x-mail::button>
@endif

## Per site

@foreach ($sites as $host => $forms)
**{{ $host === '' ? 'No website set' : $md($host) }}**

@foreach ($forms as $form)
- {{ $md($form->name) }}: {{ $line($form) }}
@endforeach

@endforeach
<p class="muted">You get this every Monday. <a href="{{ $stopUrl }}">Turn it off</a>, or switch it back on under Settings.</p>
</x-mail::message>
