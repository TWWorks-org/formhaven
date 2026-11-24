{{-- Plain text: printed raw on purpose, since HTML escaping would turn a link's & into &amp; and break it. --}}
@php($from = $submission->senderLabel())
{!! $submission->form->name !!}@if ($submission->sourcePage()) | {!! $submission->sourcePage() !!}@endif


{!! $from ? 'New message from '.$from : 'New message on '.$submission->form->name !!}

@include('mail.partials.fields-text', ['fields' => $submission->mailAnswers()])
@if ($submission->replyToAddress())

Press Reply to answer {!! $submission->replyToAddress() !!}.
@endif

All messages for this form: {!! route('forms.show', $submission->form) !!}
Spam? Block this sender: {!! $submission->blockUrl() !!}

--
@if ($submission->form->store_submissions)
{!! \App\Support\Operator::storedInEu() ? 'Stored in the EU, deleted' : 'Deleted' !!} after {!! $submission->form->retention_days !!} days.
@else
Not kept: deleted from FormHaven once this email is sent.
@endif
