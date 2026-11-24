<x-mail::message>
@if ($paid)
@if ($level === '100')
# You used this month's included submissions

You received {{ number_format($used) }} submissions this month, all {{ number_format($limit) }} your forms include. Every new message is still emailed to you. Each extra 1,000 costs €{{ $extraPrice }}, added to your next invoice once the month ends.
@else
# You used {{ $level }}% of this month's included submissions

{{ number_format($used) }} of the {{ number_format($limit) }} submissions your forms include are used this month. Past that, every message is still emailed to you, and each extra 1,000 costs €{{ $extraPrice }}, added to your next invoice once the month ends.
@endif
@elseif ($level === 'stopped')
# Emails for new messages are paused

You received {{ number_format($used) }} submissions this month, past the {{ number_format($limit) }} your trial includes and the {{ number_format($cutoff - $limit) }} extra we send on top. New messages are still saved in your dashboard, but we no longer email them to you.

Subscribe and emails start again straight away. Otherwise they start again on the 1st of next month.
@elseif ($level === '100')
# You reached this month's limit

You received {{ number_format($used) }} of the {{ number_format($limit) }} submissions your trial includes this month. We keep emailing the next {{ number_format($cutoff - $limit) }}, then new messages are only saved in your dashboard until you subscribe or the 1st of next month.
@else
# You used {{ $level }}% of this month's submissions

{{ number_format($used) }} of the {{ number_format($limit) }} submissions your trial includes are used this month. Nothing changes yet: every message is still emailed to you.
@endif

<x-mail::button :url="route('billing')">
See usage and billing
</x-mail::button>

Spam never counts towards your submissions.
</x-mail::message>
