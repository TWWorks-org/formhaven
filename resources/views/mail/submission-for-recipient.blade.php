{{-- A recipient's copy: usually the agency's client, so it carries the owner's name and none of ours. Same look as the FormHaven theme, styled inline. --}}
@php($from = $submission->senderLabel())
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $submission->mailSubject() }}</title>
</head>
<body style="margin:0;padding:24px 12px;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#18181b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e4e4e7;border-radius:10px;">
        <tr>
            <td style="padding:28px;">
                <p style="margin:0 0 6px;font-size:13px;line-height:1.5;color:#71717a;">{{ $submission->form->name }}@if ($submission->sourcePage()) | {{ $submission->sourcePage() }}@endif</p>
                <h1 style="margin:0 0 14px;font-size:20px;line-height:1.3;font-weight:600;">{{ $from ? 'New message from '.$from : 'New message on '.$submission->form->name }}</h1>
@include('mail.partials.fields', ['fields' => $submission->mailAnswers()])
                @if ($submission->replyToAddress())
                    <p style="margin:0;font-size:15px;line-height:1.6;">Press Reply to answer {{ $submission->replyToAddress() }}.</p>
                @endif
            </td>
        </tr>
    </table>
    @if ($recipient->canViewMessages($submission->form))
        <p style="max-width:600px;margin:16px auto 0;font-size:14px;line-height:1.5;color:#3f3f46;">{{ $submission->form->store_submissions ? 'Every stored message from this form, with a count per month:' : 'How many messages this form gets per month:' }} <a href="{{ $recipient->messagesUrl() }}" style="color:#18181b;">open your messages page</a></p>
    @endif
    <p style="max-width:600px;margin:16px auto 0;font-size:12px;line-height:1.5;color:#a1a1aa;">
        @if ($submission->form->store_submissions)
            {{ \App\Support\Operator::storedInEu() ? 'Stored in the EU, deleted' : 'Deleted' }} after {{ $submission->form->retention_days }} days.
        @else
            Not kept: the answers are deleted once this email is sent.
        @endif
        You get these because {{ $submission->form->senderName() }} added {{ $recipient->email }} to this form.
        <a href="{{ $recipient->stopUrl() }}" style="color:#71717a;">Stop these emails</a>
    </p>
</body>
</html>
