{{-- Plain text: printed raw on purpose, since HTML escaping would turn a link's & into &amp; and break it. --}}
@php($messages = fn (int $n) => $n.' '.($n === 1 ? 'message' : 'messages'))
@php($line = fn ($form) => $messages($form->digest_week).($form->digest_before !== null ? ' ('.$form->digest_before.' the week before)' : ' (keeps messages under '.\App\Mail\WeeklyDigest::COMPARE_DAYS.' days, so no comparison)').($form->digest_spam ? ', '.$form->digest_spam.' spam caught' : ''))
Your forms last week

{!! $messages($week).' came in across your forms in the last 7 days'.($before !== null ? ', against '.$before.' the week before' : '').'.' !!} {!! $spam ? $spam.' spam '.($spam === 1 ? 'message was' : 'messages were').' kept out of your inbox.' : '' !!}
@if ($problems !== [])

NEEDS A LOOK
@foreach ($problems as $item)

- {!! $item['form']->name.($item['form']->siteHost() ? ' ('.$item['form']->siteHost().')' : '') !!}: {!! $item['problem']['message'] !!} {!! $item['problem']['fix'] !!}
@endforeach

Open your forms: {!! route('forms.index') !!}
@endif

PER SITE
@foreach ($sites as $host => $forms)

{!! $host === '' ? 'No website set' : $host !!}
@foreach ($forms as $form)
- {!! $form->name !!}: {!! $line($form) !!}
@endforeach
@endforeach

--
You get this every Monday. Turn it off: {!! $stopUrl !!}
