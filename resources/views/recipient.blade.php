@php
    [$title, $text] = match ($state) {
        'confirm' => ['Get messages from '.$form->name.'?', $form->senderName().' wants every message sent through this form emailed to '.$email.'. Nothing is sent until you confirm.'],
        'confirmed' => ['You will get these messages', 'Every message sent through '.$form->name.' now arrives at '.$email.'. Each email has a link to stop them.'],
        'stop' => ['Stop messages from '.$form->name.'?', $email.' will no longer get messages sent through this form.'],
        'stopped' => ['Stopped', $email.' gets no more messages from '.$form->name.'. Ask '.$form->senderName().' if you want them again.'],
        'notice' => [$title, $text],
    };
@endphp
<x-visitor-page :title="$title">
    <x-slot:heading>{{ $title }}</x-slot:heading>
    <p>{{ $text }}</p>
    {{-- $action: a notice that asks for one confirming press, such as the account deletion link. --}}
    @if (in_array($state, ['confirm', 'stop'], true) || isset($action))
        <form method="POST" action="{{ request()->fullUrl() }}">
            @csrf
            <button type="submit" class="back" style="border: 0; font: inherit; cursor: pointer;">{{ $action ?? ($state === 'confirm' ? 'Yes, send them to me' : 'Stop these emails') }}</button>
        </form>
    @endif
</x-visitor-page>
