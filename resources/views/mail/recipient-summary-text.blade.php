{{-- Plain text: printed raw on purpose, since HTML escaping would turn a link's & into &amp; and break it. --}}
{!! $form->name !!}@if ($form->siteHost()) | {!! $form->siteHost() !!}@endif


{!! ucfirst($countLabel) !!} last week

From {!! $weekLabel !!}, {!! $countLabel !!} came in through {!! $form->name !!}.

Did you not see {!! $count === 1 ? 'it' : 'them' !!} in your inbox? Look in your spam or junk folder, and mark {!! $fromAddress !!} as a safe sender so the next ones land in your inbox.

{!! $form->store_submissions ? ($count === 1 ? 'Read it' : 'Read them').' on your messages page:' : 'See the count per month:' !!} {!! $recipient->messagesUrl() !!}

--
You get this weekly count because {!! $form->senderName() !!} added {!! $recipient->email !!} to this form.
Stop the weekly count (emails about each message keep coming): {!! $recipient->summaryStopUrl() !!}
