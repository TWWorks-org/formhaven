{{-- The weekly count to a recipient: the owner's name, none of ours, and nothing a visitor typed. Same look as their other emails, styled inline. --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $form->name }}: {{ $countLabel }} last week</title>
</head>
<body style="margin:0;padding:24px 12px;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#18181b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e4e4e7;border-radius:10px;">
        <tr>
            <td style="padding:28px;font-size:16px;line-height:1.6;">
                <p style="margin:0 0 6px;font-size:13px;line-height:1.5;color:#71717a;">{{ $form->name }}@if ($form->siteHost()) | {{ $form->siteHost() }}@endif</p>
                <h1 style="margin:0 0 14px;font-size:20px;line-height:1.3;font-weight:600;">{{ ucfirst($countLabel) }} last week</h1>
                <p style="margin:0 0 14px;">From {{ $weekLabel }}, {{ $countLabel }} came in through {{ $form->name }}.</p>
                <p style="margin:0 0 20px;">Did you not see {{ $count === 1 ? 'it' : 'them' }} in your inbox? Look in your spam or junk folder, and mark {{ $fromAddress }} as a safe sender so the next ones land in your inbox.</p>
                <p style="margin:0;"><a href="{{ $recipient->messagesUrl() }}" style="display:inline-block;background:#18181b;color:#ffffff;padding:10px 18px;border-radius:6px;text-decoration:none;font-weight:600;">{{ $form->store_submissions ? ($count === 1 ? 'Read it' : 'Read them').' on your messages page' : 'See the count per month' }}</a></p>
            </td>
        </tr>
    </table>
    <p style="max-width:600px;margin:16px auto 0;font-size:12px;line-height:1.5;color:#a1a1aa;">
        You get this weekly count because {{ $form->senderName() }} added {{ $recipient->email }} to this form.
        <a href="{{ $recipient->summaryStopUrl() }}" style="color:#71717a;">Stop the weekly count</a> (emails about each message keep coming).
    </p>
</body>
</html>
