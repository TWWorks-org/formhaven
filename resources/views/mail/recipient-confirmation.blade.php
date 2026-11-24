{{-- Plain HTML, not Markdown: the form and sender names are typed by the account holder and go to an address that has not agreed to anything yet. --}}
@php($form = $recipient->form)
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Get messages from {{ $form->name }}?</title>
</head>
<body style="margin:0;padding:24px 12px;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#18181b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e4e4e7;border-radius:10px;">
        <tr>
            <td style="padding:28px;font-size:16px;line-height:1.6;">
                <h1 style="margin:0 0 14px;font-size:20px;line-height:1.3;font-weight:600;">Get messages from {{ $form->name }}?</h1>
                <p style="margin:0 0 20px;">{{ $form->senderName() }} wants every message sent through the form "{{ $form->name }}"{{ $form->siteHost() ? ' on '.$form->siteHost() : '' }} to be emailed to {{ $recipient->email }}.</p>
                <p style="margin:0 0 20px;"><a href="{{ $recipient->confirmUrl() }}" style="display:inline-block;background:#18181b;color:#ffffff;padding:10px 18px;border-radius:6px;text-decoration:none;font-weight:600;">Yes, send them to me</a></p>
                <p style="margin:0 0 14px;">Nothing is sent until you confirm. If you do not know {{ $form->senderName() }}, ignore this email. The link works for {{ \App\Models\FormRecipient::CONFIRM_DAYS }} days.</p>
                <p style="margin:0;color:#52525b;">{{ $form->senderName() }} uses FormHaven to receive this form. Messages are stored{{ \App\Support\Operator::storedInEu() ? ' in the EU' : '' }} and deleted {{ $form->store_submissions ? 'after '.$form->retention_days.' days' : 'once emailed' }}. Every email has a link to stop them.</p>
            </td>
        </tr>
    </table>
</body>
</html>
