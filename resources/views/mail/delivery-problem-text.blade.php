{{-- Plain text: printed raw on purpose, since HTML escaping would turn a link's & into &amp; and break it. --}}
{!! $formName !!}

@if ($confirmation)
The confirmation email to {!! $email !!} did not arrive

You added {!! $email !!} to {!! $formName !!}. We emailed it a link to confirm, and that email could not be delivered. {!! $explain !!}

Until the address confirms, it gets no messages from this form.

What to do: check the spelling. If it is wrong, remove the address and add the right one. If it is right, ask them whether the mailbox works, then press Resend link.
@else
A message did not reach {!! $email !!}

{!! $explain !!}

The message is saved in your dashboard, so nothing is lost. {!! $waiting === 1 ? '1 message for this address is' : $waiting.' messages for this address are' !!} waiting there.

What to do: {!! $fix !!}
@endif

Open {!! $formName !!}: {!! $formUrl !!}

--
We send this at most once a day per address.
