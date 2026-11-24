{{-- The address is whatever the reporter typed, so nothing they typed is repeated here: otherwise this form would send anyone's text to anyone from our domain. --}}
<x-mail::message>
# We received your report

Your report #{{ $report->id }} about a form on FormHaven reached us. If you did not send it, ignore this email.

We look at every report by hand and email you our decision, with the reasons and what you can do if you disagree. Reply to this email if you want to add anything.
</x-mail::message>
