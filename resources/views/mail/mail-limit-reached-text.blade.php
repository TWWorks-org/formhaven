{{-- Plain text: printed raw on purpose, since HTML escaping would turn a link's & into &amp; and break it. --}}
{!! $formName !!}

{!! $formName !!} reached its daily email limit

A form can send {!! $limit !!} emails a day: one for each message, to each address it emails. This form reached that today, so we stopped emailing its messages until 00:00 UTC.

Every message is still saved in your dashboard, and your visitors saw the usual thank-you.

What to do: after 00:00 UTC, open the form and press Send again to get the held emails. If the messages are spam, turn on the bot check or list your site under Allowed sites in the form's Settings. If the form really gets this many messages, write to {!! config('mail.from.address') !!}.

Open {!! $formName !!}: {!! $formUrl !!}

--
We send this at most once a day per form.
