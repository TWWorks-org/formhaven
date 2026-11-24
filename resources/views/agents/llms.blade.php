@php($perForm = config('billing.per_form'))
@php($bill = \App\Support\Bill::class)
# FormHaven

@if (\App\Support\Mode::hosted())
> EU-hosted form backend an AI agent can set up end to end. A website's HTML form posts to a FormHaven endpoint; each submission is stored in the EU, spam filtered and emailed to the account owner. An agent can start a form without an account, change every setting, get code per stack and get the privacy-policy paragraph for the site. For freelancers and agencies building static or AI-built sites.
@else
> {{ \App\Support\Operator::storedInEu() ? 'EU-hosted' : 'Self-hosted' }} form backend an AI agent can set up end to end, running at {{ url('/') }}. A website's HTML form posts to a FormHaven endpoint; each submission is stored {{ \App\Support\Operator::storageCountry() ? 'in '.\App\Support\Operator::storageCountry() : 'on this install' }}, spam filtered and emailed to the account owner. An agent can start a form, change every setting, get code per stack and get the privacy-policy paragraph for the site.
@endif

## Key facts

@if (\App\Support\Mode::hosted())
- Pricing: {{ $bill::format($perForm['monthly_eur']) }} per form a month ({{ $bill::format($perForm['yearly_eur']) }} per form a year), for at least {{ $perForm['min_forms'] }} forms. From the {{ $bill::firstLowerForm() }} form on: {{ $bill::format($perForm['lower_monthly_eur']) }} per form a month ({{ $bill::format($perForm['lower_yearly_eur']) }} per form a year). Examples: {{ $perForm['min_forms'] }} forms {{ $bill::format($bill::monthly($perForm['min_forms'])) }} a month, 25 forms {{ $bill::format($bill::monthly(25)) }} a month, 100 forms {{ $bill::format($bill::monthly(100)) }} a month. Every feature on every account. The bill follows the number of forms: creating a form adds one, deleting one removes it from that day.
- Submissions: each form includes {{ number_format($perForm['submissions']) }} a month, whatever it costs, shared across the account's forms. Past that, every message is still emailed and each extra 1,000 costs {{ $bill::format($perForm['extra_eur']) }}, added to the next invoice once the month ends. Spam never counts.
- Trial: {{ config('billing.trial_days') }} days, no card needed, up to {{ config('billing.trial_forms') }} forms. Past the trial's submissions, emails continue for {{ (int) round(config('billing.email_grace') * 100) }}% more, then messages are saved but not emailed until the owner subscribes or the 1st of the month. No free plan.
- Data location: submissions stored on servers of {{ config('legal.hosting.provider') }} in {{ config('legal.hosting.place.en') }}; emails are sent through {{ config('legal.mailer') }}. Each form keeps submissions 1 to 365 days, or none. A data processing agreement applies to every account: {{ route('legal', 'dpa') }}
- Subprocessors: {{ route('legal', 'subprocessors') }}
@else
- Self-hosted install: no bill and no limit on forms or submissions. The daily email limits below still apply.
- Data location: {{ config('legal.hosting.place.en') ? 'submissions stored on servers'.(config('legal.hosting.provider') ? ' of '.config('legal.hosting.provider') : '').' in '.config('legal.hosting.place.en') : 'submissions stored in this install\'s own database' }}{{ config('legal.mailer') ? '; emails are sent through '.config('legal.mailer') : '' }}. Each form keeps submissions 1 to 365 days, or none.
@endif

## Start without an account

For an agent that has no FormHaven API key. One request reserves a form; the site owner switches it on with one click.

1. POST {{ url('/api/v1/start') }} with JSON {"name": "Contact form on example.nl", "site_url": "https://example.nl" (optional), "redirect_url": "..." (optional), "retention_days": {{ \App\Models\Form::DEFAULT_RETENTION_DAYS }} (optional, 1 to 365), "client": "Claude Code" (optional: what you are)}. No auth.
2. The 201 answer: {"data": {"id", "endpoint", "status": "waiting_for_owner", "claim_url", "setup_key", "expires_at"}, "html": "<form ...>", "next": "..."}.
3. Put data.endpoint in the site's form action straight away (method POST, every input named, plus the hidden _gotcha input, as in "html").
4. Give the site owner data.claim_url. {{ \App\Support\Mode::hosted() ? 'They create a FormHaven account ('.config('billing.trial_days').'-day trial, no card) or log in, and press Activate.' : 'They log in to their account on this install (or create the first one) and press Activate.' }} Until then the endpoint answers 403 with code FORM_NOT_ACTIVE and stores nothing. The link lapses after {{ \App\Models\FormClaim::DAYS }} days; then start again.
5. Keep data.setup_key private. GET {{ url('/api/v1/start/{id}') }} with "Authorization: Bearer <setup_key>" returns "status": "waiting_for_owner" or "active". Once active, the same key works on the REST API and the MCP server below, for this one form only: it cannot see the account's other forms or create new ones. It works for {{ \App\Actions\StartForm::setupKeyDays() }} days after activation (the status answer gives "setup_key_expires_at"), and the owner can revoke it on the form's page. An expired key gets 401 with code {{ \App\Support\ExpiredSetupKey::CODE }}; to go on, the site owner creates an API key on the API keys page ({{ route('api-keys') }}).

Limits: 10 starts an hour per address.
@if (\App\Support\Mode::selfHosted())

On this install new accounts can be made only while it has none (unless its owner set ALLOW_REGISTRATION=true). Once registration is closed, POST {{ url('/api/v1/start') }} without a key answers 403 with code {{ \App\Support\Mode::REGISTRATION_CLOSED }}; send an API key of the existing account as "Authorization: Bearer KEY" with it, or create the form with POST {{ url('/api/v1/forms') }}.
@endif

## Make a form work

1. Create a form (dashboard, REST API or MCP) and take its endpoint, for example {{ url('/f/FORM_ID') }}
2. Set the HTML form's action to that endpoint with method POST. Every input needs a name.
3. Add a hidden input named _gotcha. Bots fill it in; those submissions are kept as spam and not emailed.
   Allowed sites (optional; dashboard, PATCH "allowed_domains" or MCP update_form): list the form's domains and a post from any other site, judged by the browser's Origin header and then the Referer, is kept as spam and not emailed. A listed domain covers its subdomains; localhost always passes. Empty means any site. GET a form returns "allowed_domains", and each spam submission returns "spam_reason" in plain words.
   Always on: submissions with forum link code ([url=...] or <a href>) or more than {{ \App\Actions\ReceiveSubmission::MAX_LINKS }} links go to spam.
   Bot check (optional, per form): add {!! \App\Models\Form::botCheckScript() !!} once to every page with the form, then turn it on (dashboard, PATCH {"bot_check": true} or MCP update_form_spam). The script solves a small proof-of-work puzzle in the visitor's browser before the form sends and puts it in a hidden _botcheck field; visitors see nothing, and it sets no cookies. With it on, a post without a solved puzzle goes to spam, including from a browser with JavaScript off. From your own JavaScript, new FormData(form) carries _botcheck; a JSON body must copy that field's value.
   Spam words (optional, per form): up to {{ \App\Actions\FormSpamSettings::MAX_WORDS }} words or phrases; a submission containing one goes to spam. Case and accents are ignored. To block a company that keeps sending pitches under new names, add "@company.com"; at a shared provider such as Gmail, add the one address instead. The owner's email about each message has a "Block this sender" link that does this.
4. From JavaScript: POST JSON or form data with "Accept: application/json". Success is {"ok": true, "message": "...", "next": "URL"}. A refusal is {"ok": false, "message": "...", "error": "...", "errors": [{"code": "TYPE_EMAIL", "field": "email", "message": "..."}]}, the same errors array Formspree returns. Codes: FORM_NOT_FOUND, FORM_NOT_ACTIVE (reserved by an agent, not switched on yet), FORM_PAUSED, EMPTY, TOO_MANY_FIELDS, TYPE_EMAIL.

Optional fields (Formspree names, so a form moved from Formspree keeps working):

- email or _replyto: the visitor's address. The owner's Reply goes to it. A filled-in address that is not valid is refused with TYPE_EMAIL.
- _subject: the subject line of the owner's email.
- _next: where the visitor goes after sending. Only a page on the form's saved website or on the site the form was sent from; a relative path ("/thanks") opens on the site the form was sent from. A thank-you page saved in the dashboard wins.

Formspark names work too: _honeypot (same as _gotcha), _redirect (same as _next), _email.subject (same as _subject), _email.replyto (same as _replyto), and _error: where a full-page post that was refused goes instead of our error page, under the same rules as _next.

FormSubmit names: _honey works as the honeypot, and _next, _subject and _replyto are the same as ours. Its other control fields (_captcha, _template, _cc, _autoresponse, _blacklist, _webhook) are ignored and never stored; set recipients, spam words and the webhook on the form instead.

Emails: each submission is emailed to the account owner. A form can also email up to 5 more addresses (usually the agency's client), each only after it confirms by a link we send it. Their copy shows the sender name the owner set (else the account name) and no FormHaven branding, and has a link to stop. The owner can turn their own copy off; until a recipient has confirmed, the owner still gets every message. With "recipients_can_view" on (off by default), each confirmed recipient's emails also link to a read-only page of the form's stored messages, newest first, with counts for this month and last month, so the client has a second place to look besides their inbox. On a form that keeps no submissions the page shows only the counts. The link is signed, needs no login and works for anyone it is forwarded to; switching the setting off or removing the address closes it at once. While "recipients_can_view" is on, each confirmed recipient also gets a weekly count ("recipients_weekly_summary", on by default): a short email on Monday morning, Amsterdam time, with how many real messages came in through the form the week before, the link to its messages page and a line asking it to check its spam folder and mark our sending address as safe if it missed any. It holds nothing a visitor typed, carries the same sender name, is sent only in a week with at least one message and only while the account's emails are not paused, and goes out later in the week when the day's sending cap is reached. Its stop link stops only the weekly count; the emails about each message keep coming. It is one more email to the client's inbox, not a check on where the others landed. Recipients are managed in the dashboard, the REST API or MCP (below).

When an email cannot be delivered (the mail server refuses the address, or our mail provider no longer sends to it after an earlier bounce or spam complaint), the message stays saved, even on a form that keeps no submissions, until its retention days pass. The owner gets one email about it per address per day, unless the failing address is their own. Daily email limits: each form sends at most {{ \App\Support\MailBudget::perForm() }} emails about submissions a day (one per message for each address it emails), and all forms together at most {{ \App\Support\MailBudget::limit(\App\Support\MailBudget::NOTIFICATIONS) }}, counted per UTC day. Past a limit the email is held: the visitor gets the same success answer, the message stays saved, health shows UNDELIVERED with the reason, the owner gets one email a day about a form over its limit, and retry_deliveries sends the held emails after 00:00 UTC. Every form in GET /api/v1/forms and MCP list_forms carries "health": {"status": "ok" or "attention", "problems": [{"code", "email", "message", "fix"}]}. Codes: {{ implode(', ', \App\Models\Form::HEALTH_CODES) }}. UNDELIVERED: messages that did not reach an address, or were held by a daily email limit (its message says which). CONFIRMATION_FAILED: a recipient's confirmation link did not arrive. RECIPIENT_UNCONFIRMED: a recipient added more than {{ \App\Models\Form::UNCONFIRMED_DAYS }} days ago has not pressed its link. OWNER_UNVERIFIED: the owner's own address is not verified and no recipient is confirmed, so nobody is emailed. MONITOR_KEY_REFUSED: DidMyFormSend answered 401 or 410 to the form's DidMyFormSend key, so the monitor gets no reports; the fix is a current key from the monitor's page, or an empty monitor_key. QUIET: a form with at least {{ \App\Models\Form::QUIET_MIN_MESSAGES }} real messages has had none for {{ \App\Models\Form::QUIET_GAP_FACTOR }} times its usual gap and at least {{ \App\Models\Form::QUIET_MIN_DAYS }} days; on its own it makes the status "quiet", not "attention", and the fix is a test sent from the site itself. A submission lists the addresses it did not reach under "undelivered", and a recipient its last "delivery_problem". Once the address works, POST {{ url('/api/v1/forms/{id}/deliveries/retry') }} or MCP retry_deliveries sends them again (up to 50 per call, 3 calls an hour per form). A mailbox that was closed is only seen from the second message on: the first email to it is accepted and bounces later, out of our sight. Mail that was delivered but went to a spam folder or was never read is not detected. Every Monday the owner also gets a summary: messages per form last week and the week before, spam caught, and every problem above. It is on for every account and turned off under Settings or by the link in the email.

Delivery monitor (optional, per form, off unless a key is saved): DidMyFormSend (https://didmyformsend.com) is a separate monitor that alerts the site owner when a form's emails stop going out. Save the owner's DidMyFormSend heartbeat key (fmh_ and 40 letters and digits) as "monitor_key" (dashboard Settings, PATCH or MCP update_form; an empty value removes it). After each submission, once all of its emails have a final outcome, FormHaven sends didmyformsend.com one report: "sent" when the mail service accepted every email, "failed" with the failure kind (bounced, suppressed or error) when any email failed after its last try, the mail service's name, the time, and each of the form's notification addresses (up to 5) cut to its first character and domain plus a SHA-256 hash of the full address. A hash of an email address is still personal data. Nothing a visitor wrote, no visitor address, no form name and no site address is sent. Spam, emails paused by the plan and emails held by a daily email limit are not reported; Send again reports again. The key is never returned: a form object carries "monitor": {"on", "key_prefix"} with the key's first 8 characters. Without a key, FormHaven makes no request to DidMyFormSend at all.

Storage: each form keeps submissions for 1 to 365 days (default {{ \App\Models\Form::DEFAULT_RETENTION_DAYS }}), searchable and exportable as CSV in the dashboard. A form can instead be set to keep none: each submission is emailed and its answers are then deleted (the API returns "stored": false for it). A submission that could not be emailed is kept so it is not lost.

Webhooks: a form can send each non-spam submission to an https URL set in the dashboard, as a JSON POST: {"event": "submission.created", "id": "...", "test": false, "form": {"id": "...", "name": "..."}, "received_at": "ISO 8601", "reply_to": "address or null", "page": "host/path or null", "fields": {...}}. Header FormHaven-Signature: t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<raw body>" with the form's signing secret>. Any 2xx answer counts as delivered; anything else is retried 4 times over about 37 minutes. Redirects are not followed, and private or local addresses are refused. The dashboard's test event has the same shape with "test": true. A Slack incoming webhook address (hooks.slack.com) gets the same submission as a Slack message ({"text": "..."}) instead of this JSON.

Limits per submission: 50 fields, 5,000 characters per answer, 10 submissions per minute per visitor per form.

## API for agents

Authentication: an API key from the dashboard (API keys page), or the setup_key from "Start without an account" once the owner has activated the form (for {{ \App\Actions\StartForm::setupKeyDays() }} days from then), sent as "Authorization: Bearer KEY".

- MCP sign-in without a key: MCP clients that support OAuth connect with just {{ url('/mcp') }}. A request without a token gets 401 with WWW-Authenticate pointing at {{ url('/.well-known/oauth-protected-resource/mcp') }}; the authorization server ({{ url('/.well-known/oauth-authorization-server') }}) supports Dynamic Client Registration, PKCE with S256 and rotating refresh tokens. The person signs in and presses Allow; the access token lasts an hour and works on the MCP server only, not the REST API.
- MCP server: {{ url('/mcp') }} (Streamable HTTP, POST only, same Bearer key; tools: {{ \App\Mcp\Servers\FormHavenServer::toolNames()->implode(', ') }}). Claude Code: claude mcp add --transport http formhaven {{ url('/mcp') }} --header "Authorization: Bearer KEY"
- REST: GET {{ url('/api/v1/forms') }}, POST {{ url('/api/v1/forms') }} {"name": "...", "site_url": "https://example.nl" (optional: the thank-you page links back to it), "redirect_url": "..." (optional)}, GET {{ url('/api/v1/forms/{id}') }}, GET {{ url('/api/v1/forms/{id}/submissions') }}?spam=0&per_page=25
- REST, who gets the emails: POST {{ url('/api/v1/forms/{id}/recipients') }} {"email": "..."} (emails the address a confirmation link), POST {{ url('/api/v1/forms/{id}/recipients/{email}/resend') }}, DELETE {{ url('/api/v1/forms/{id}/recipients/{email}') }}.
- REST, settings: PATCH {{ url('/api/v1/forms/{id}') }} with any of: "name", "site_url", "redirect_url", "retention_days" (1 to 365), "store_submissions" (false: email each submission, then delete its answers), "webhook_url" (public https; the answer then carries "webhook_signing_secret" once), "allowed_domains" (list of up to 20 domains), "sender_name", "notify_owner", "recipients_can_view", "recipients_weekly_summary", "bot_check", "spam_words" (list), "monitor_key" (a DidMyFormSend key, or empty to remove it). Any other field is refused with 422 and nothing is saved. A form object lists "recipients": [{"email", "confirmed", "added_at", "delivery_problem", "messages_url", "weekly_summary", "weekly_summary_stopped"}], "sender_name", "notify_owner", "recipients_can_view", "recipients_weekly_summary", "bot_check", "bot_check_script", "spam_words" and "monitor" ({"on", "key_prefix"}, never the key). "messages_url" is the recipient's messages page while it is open (confirmed, with recipients_can_view on), else null. "weekly_summary" is true while the address gets the weekly count; "weekly_summary_stopped" is true once it pressed stop in that email itself.
- REST, the privacy-policy paragraph for the site: GET {{ url('/api/v1/forms/{id}/privacy-text') }}?lang=en (en, nl or de). Returns "heading", "paragraph", "form_notice_html" (a line for above the submit button), "facts", "note" and "generated_on". It is built from the form's live settings: who processes the messages, where they are stored, who sends the emails and how long FormHaven keeps a copy. Not legal advice; tell the site owner to check it.
- REST, delete a form for a site that is gone: DELETE {{ url('/api/v1/forms/{id}') }} {"confirm": "<the form's name, exactly>"}. It deletes the form and its stored submissions{{ \App\Support\Mode::hosted() ? ", and the bill counts one form fewer from that day" : "" }}. Full API keys only; ask the site owner first. MCP: delete_form.
- Code for other stacks (HTML, JavaScript fetch, React, Astro) with the form's address filled in: MCP get_form_code.
- Errors are JSON with a "message" field.

@if (\App\Support\Mode::hosted())
## Pages

- Home: {{ route('home') }}
- Pricing: {{ route('pricing') }}
- Form templates: {{ route('templates.index') }}
@foreach (\App\Http\Controllers\ContentController::templates() as $slug => $template)
  - {{ $template['name'] }}: {{ route('templates.show', $slug) }}
@endforeach
- Setup guides, grouped by stack: {{ route('guides.index') }}
@foreach (\App\Http\Controllers\ContentController::guides() as $slug => $guide)
- {{ $guide['name'] }} guide: {{ route('guides.show', $slug) }}
@endforeach
@foreach (\App\Http\Controllers\ContentController::alternatives() as $slug => $alternative)
- FormHaven vs {{ $alternative['name'] }}: {{ route('alternatives.show', $slug) }}
@endforeach
- Privacy: {{ route('legal', 'privacy') }}
- Terms: {{ route('legal', 'terms') }}

Contact: {{ config('legal.email') }}
@else
## Pages

- Dashboard: {{ route('forms.index') }}
- API keys: {{ route('api-keys') }}
@endif
