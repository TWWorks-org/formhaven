{{-- Plain text: printed raw on purpose, since HTML escaping would turn a link's & into &amp; and break it. --}}
@php($form = $recipient->form)
Get messages from {!! $form->name !!}?

{!! $form->senderName() !!} wants every message sent through the form "{!! $form->name !!}"{!! $form->siteHost() ? ' on '.$form->siteHost() : '' !!} to be emailed to {!! $recipient->email !!}.

Yes, send them to me: {!! $recipient->confirmUrl() !!}

Nothing is sent until you confirm. If you do not know {!! $form->senderName() !!}, ignore this email. The link works for {!! \App\Models\FormRecipient::CONFIRM_DAYS !!} days.

{!! $form->senderName() !!} uses FormHaven to receive this form. Messages are stored{!! \App\Support\Operator::storedInEu() ? ' in the EU' : '' !!} and deleted {!! $form->store_submissions ? 'after '.$form->retention_days.' days' : 'once emailed' !!}. Every email has a link to stop them.
