{{-- Everything here except the id was typed by the reporter, so it goes through the fields block, which escapes it and keeps it from becoming a link. --}}
<x-mail::message>
# Abuse report #{{ $report->id }}

<x-mail::fields :fields="['Form' => $report->url, 'From' => $report->name, 'Email' => $report->email, 'Why it is illegal or abusive' => $report->reason]" />

The reporter has a receipt. Decide in a timely, objective way and reply to them with the decision and how to contest it. If you pause the form or account, send its owner a statement of reasons: what was done, the facts, which rule in the terms, and how to contest it.
</x-mail::message>
