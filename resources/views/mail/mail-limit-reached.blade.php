{{-- The form name is typed by a person, so it goes through $md and never becomes a link or image. --}}
@php($md = fn ($text) => \App\Support\MarkdownText::escape(str_replace(["\r\n", "\n", '|'], [' ', ' ', '/'], (string) $text)))
<x-mail::message>
<p class="muted">{{ $formName }}</p>

# {{ $md($formName) }} reached its daily email limit

A form can send {{ $limit }} emails a day: one for each message, to each address it emails. This form reached that today, so we stopped emailing its messages until 00:00 UTC.

Every message is still saved in your dashboard, and your visitors saw the usual thank-you.

**What to do:** after 00:00 UTC, open the form and press Send again to get the held emails. If the messages are spam, turn on the bot check or list your site under Allowed sites in the form's Settings. If the form really gets this many messages, write to {{ config('mail.from.address') }}.

<x-mail::button :url="$formUrl">
Open {{ $md($formName) }}
</x-mail::button>

<p class="muted">We send this at most once a day per form.</p>
</x-mail::message>
