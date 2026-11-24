@php($md = fn ($text) => \App\Support\MarkdownText::escape(str_replace(["\r\n", "\n", '|'], [' ', ' ', '/'], (string) $text)))
<x-mail::message>
# Today's {{ $limit }} submission emails are used up

FormHaven sent its {{ $limit }} emails about submissions for today (MAIL_BUDGET_NOTIFICATIONS). Until 00:00 UTC, new messages are saved but not emailed. Each form's health block tells its owner, and Send again delivers them after the reset.

@if ($topForm)
**The form that sent the most:** {{ $md($topForm) }}, with {{ $topCount }} of its {{ $perForm }} a day.
@endif

**What to check:** whether that form is getting spam or a flood from one site. If the traffic is real, raise MAIL_BUDGET_NOTIFICATIONS on the server, keeping room under the daily quota the sites share.

<p class="muted">We send this at most once a day.</p>
</x-mail::message>
