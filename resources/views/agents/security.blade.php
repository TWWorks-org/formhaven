# security.txt (RFC 9116): where security researchers report vulnerabilities.
# Expires is REQUIRED by the spec and should be ~1 year out.
# Bump it yearly (add a calendar reminder with the quarterly security review).
Contact: mailto:{{ config('legal.support_email') }}
Expires: 2027-06-10T00:00:00.000Z
Preferred-Languages: en, nl
Canonical: {{ rtrim(config('app.url'), '/') }}/.well-known/security.txt
{{-- Maintainer note, never sent: Contact reads SUPPORT_EMAIL (config/legal.php), so set it to an address that reaches a person. --}}