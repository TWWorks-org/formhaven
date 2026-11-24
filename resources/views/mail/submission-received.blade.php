{{-- Everything a visitor or the account typed is escaped: lines here go through $md so they show as typed and never become a link or image; the answers go through the fields block, which escapes its own. --}}
@php($md = fn ($text) => \App\Support\MarkdownText::escape(str_replace(["\r\n", "\n", '|'], [' ', ' ', '/'], (string) $text)))
@php($from = $submission->senderLabel())
@php($replyTo = $submission->replyToAddress())
<x-mail::message>
<p class="muted">{{ $submission->form->name }}@if ($submission->sourcePage()) | {{ $submission->sourcePage() }}@endif</p>

# {{ $from ? 'New message from '.$md($from) : 'New message on '.$md($submission->form->name) }}

<x-mail::fields :fields="$submission->mailAnswers()" />

@if ($replyTo)
<x-mail::button :url="'mailto:'.rawurlencode($replyTo).'?subject='.rawurlencode('Re: '.$submission->mailSubject())">
Reply
</x-mail::button>
@endif

<p class="muted"><a href="{{ route('forms.show', $submission->form) }}">All messages for this form</a> | <a href="{{ $submission->blockUrl() }}">Spam? Block this sender</a> | @if ($submission->form->store_submissions){{ \App\Support\Operator::storedInEu() ? 'Stored in the EU, deleted' : 'Deleted' }} after {{ $submission->form->retention_days }} days.@else Not kept: deleted from FormHaven once this email is sent.@endif</p>
</x-mail::message>
