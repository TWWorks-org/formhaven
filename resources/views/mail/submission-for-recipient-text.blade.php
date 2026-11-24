{{-- Plain text: printed raw on purpose, since HTML escaping would turn a link's & into &amp; and break it. --}}
@php($from = $submission->senderLabel())
{!! $submission->form->name !!}@if ($submission->sourcePage()) | {!! $submission->sourcePage() !!}@endif


{!! $from ? 'New message from '.$from : 'New message on '.$submission->form->name !!}

@include('mail.partials.fields-text', ['fields' => $submission->mailAnswers()])
@if ($submission->replyToAddress())

Press Reply to answer {!! $submission->replyToAddress() !!}.
@endif
@if ($recipient->canViewMessages($submission->form))

{!! $submission->form->store_submissions ? 'Every stored message from this form, with a count per month:' : 'How many messages this form gets per month:' !!} {!! $recipient->messagesUrl() !!}
@endif

--
@if ($submission->form->store_submissions)
{!! \App\Support\Operator::storedInEu() ? 'Stored in the EU, deleted' : 'Deleted' !!} after {!! $submission->form->retention_days !!} days.
@else
Not kept: the answers are deleted once this email is sent.
@endif
You get these because {!! $submission->form->senderName() !!} added {!! $recipient->email !!} to this form.
Stop these emails: {!! $recipient->stopUrl() !!}
