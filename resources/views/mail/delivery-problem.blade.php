{{-- The form name and the address are typed by a person, so they go through $md and never become a link or image. --}}
@php($md = fn ($text) => \App\Support\MarkdownText::escape(str_replace(["\r\n", "\n", '|'], [' ', ' ', '/'], (string) $text)))
<x-mail::message>
<p class="muted">{{ $formName }}</p>

@if ($confirmation)
# The confirmation email to {{ $md($email) }} did not arrive

You added {{ $md($email) }} to {{ $md($formName) }}. We emailed it a link to confirm, and that email could not be delivered. {{ $explain }}

Until the address confirms, it gets no messages from this form.

**What to do:** check the spelling. If it is wrong, remove the address and add the right one. If it is right, ask them whether the mailbox works, then press Resend link.
@else
# A message did not reach {{ $md($email) }}

{{ $explain }}

The message is saved in your dashboard, so nothing is lost. {{ $waiting === 1 ? '1 message for this address is' : $waiting.' messages for this address are' }} waiting there.

**What to do:** {{ $fix }}
@endif

<x-mail::button :url="$formUrl">
Open {{ $md($formName) }}
</x-mail::button>

<p class="muted">We send this at most once a day per address.</p>
</x-mail::message>
