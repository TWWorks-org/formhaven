# Data processing agreement

Last updated: :updated

This agreement (the "DPA") is part of the terms between you and :entity ("FormHaven", "we") and applies automatically to every FormHaven account. It covers the personal data that visitors send through your forms and the email addresses you add to receive them, as Article 28 of the GDPR requires. Need a signed copy for your records? Email :email.

## 1. Roles

For the personal data in your forms, you decide why and how it is processed and we process it for you. Usually you are the controller and we are your processor. If you run forms on behalf of your own clients, as most agencies do, you are their processor and we are your sub-processor: this DPA then sets the obligations you pass down to us under Article 28(4), your client is the controller, and our duties to assist run to you so that you can meet yours to your client. You confirm that your client has authorised you to use FormHaven.

## 2. Subject, nature and purpose

FormHaven receives submissions sent to your form addresses, stores them, filters spam, emails them to you and to the addresses you add, sends them to a webhook if you set one, and lets you view, search, export and delete them. We process the data only for that purpose.

## 3. Your instructions

We process the data only on your documented instructions: these terms, this DPA and the settings you choose in your dashboard or through our API. That includes transfers outside the EU: we make only the transfers described in section 8. You can give further instructions by email to :email.

If Union or Member State law requires us to process the data in another way, we tell you about that requirement before we process, unless that law forbids telling you. If we believe an instruction breaks the GDPR or other data protection law, we tell you straight away and may wait to carry it out until you confirm or change it.

## 4. Duration

For as long as your account exists. Submissions are deleted:

- when they pass the retention period you set per form (1 to 365 days, 30 by default);
- when you delete them, the form, or your account;
- for a form you set to keep no submissions, as soon as the notification email is sent. If that email cannot be delivered, the submission is kept until the retention period ends, so it is not lost and can be sent again. We then keep only an empty record of when it arrived and whether it was spam, which counts towards your monthly limit and is deleted with the retention period;
- after you delete your account: straight away.

A checked copy of the database is made every night and kept for 7 nights on the same server, so data can be restored after a fault. Anything deleted above stays in those copies for up to 7 days and is then gone from them too.

## 5. Types of data and data subjects

**Data subjects:** the visitors who fill in your forms, and the people whose email addresses you add to a form to receive its submissions.

**Data:** whatever fields your form sends (typically name, email address and a message), plus, for submissions we keep, a keyed one-way hash of the visitor's IP address, their browser's user agent and the page they submitted from. For added recipients: their email address, whether and when they confirmed, and whether they stopped the weekly count. We email an added address once to ask it to confirm and send it nothing else until it does. Once confirmed it gets each submission and, while you have its messages page turned on, a weekly count of the form's messages with no submitted fields in it, unless you switch that count off. It can stop the emails itself at any time, and the weekly count on its own.

You decide which fields your forms ask for. Do not use FormHaven for special categories of personal data (health, religion, political views and the like), data on criminal convictions, passwords or payment card data, or forms aimed at children.

## 6. Our obligations

- **Confidentiality.** Everyone with access to the data is bound to confidentiality.
- **Security.** We keep the technical and organisational measures in the annex below in place, and keep them appropriate to the risk as described in Article 32.
- **Location.** We store submissions on servers in the EU.
- **Data subject requests.** You can view, export and delete submissions yourself in the dashboard and through the API. If a request reaches us directly, we pass it to you and do not answer it ourselves unless you ask us to. We help you with any request you cannot handle with those tools.
- **Your other obligations.** We help you, taking into account what we know, to meet your obligations under Articles 32 to 36: securing the processing, notifying breaches, and a data protection impact assessment or prior consultation with a supervisory authority where you need one. We supply the information about our processing these require.
- **Breaches.** We tell you without undue delay, and at the latest within 48 hours, after we become aware of a personal data breach affecting your data. We tell you what happened, which data and roughly how many people are affected, the likely consequences and what we are doing about it, and send what we do not know yet as soon as we do. We keep our own record of every breach.
- **End of the service.** Before you delete your account you can export everything, see section 9 of the terms. Deleting your account is your choice to have the data deleted rather than returned. If you want us to return the data to you instead, email :email before you delete it and we will send you the export. Copies are then deleted as set out in section 4, unless Union or Member State law requires us to keep them.
- **Proof and audits.** We give you the information you need to show that we meet this DPA, and allow audits by you or an auditor you appoint, at your expense, with 30 days' notice, at most once a year unless a breach has occurred or a supervisory authority requires it. We first try to answer an audit request with documents.

## 7. Subprocessors

You give general authorisation to the subprocessors on our [subprocessor list](/legal/subprocessors). We bind each of them by contract to the same data protection obligations as this DPA, in particular sufficient guarantees on security, and we remain fully liable to you for how they meet those obligations.

We announce a new or replaced subprocessor by email at least 30 days before it starts. You can object by email within that time. If we cannot address your objection, you may end your subscription before the change takes effect and receive a pro-rata refund of what you paid in advance.

## 8. Transfers outside the EU

We store submissions in the EU. Where a subprocessor processes data outside the European Economic Area (Cloudflare, when it carries requests and emails through its global network), the transfer relies on the EU-US Data Privacy Framework, under which Cloudflare is certified, and on the European Commission's Standard Contractual Clauses in Cloudflare's data processing addendum. We make no other transfers.

## 9. Liability

Liability under this DPA follows the limitation in our [terms](/legal/terms), except where the GDPR does not allow a limitation. That limitation does not apply to fines a supervisory authority imposes on either of us, which each party bears for its own breaches.

## 10. Order of precedence

If this DPA and the terms conflict on the processing of personal data, this DPA wins.

## Annex: technical and organisational measures

These are the measures in place. We may change them, but never to a lower level of protection.

| Area | Measure |
|---|---|
| Encryption in transit | Every connection to :site uses TLS through Cloudflare, and traffic from Cloudflare to our server is encrypted again. Emails are sent over Cloudflare's API with TLS. |
| Hosting | A virtual server at Hetzner in Falkenstein, Germany, in an ISO 27001 certified data centre. It runs only services of the same owner, each with its own database. |
| Server access | Only the owner of FormHaven has access to production. Logins use SSH keys only, password and root logins are switched off, and administration runs over a private network (Tailscale). |
| Network | The web server accepts traffic only through Cloudflare, which filters attacks before they reach us. |
| Updates | Security updates for the operating system install automatically every day. |
| Application | Passwords are stored as bcrypt hashes. Each account sees only its own forms, enforced on every request. API keys can be revoked by their owner. Logins and form addresses are rate limited. |
| Data minimisation | Visitor IP addresses are stored only as a keyed hash. Forms can be set to keep no submissions at all. Each form deletes its submissions after the retention period its owner sets. |
| Logging | Application logs record warnings, errors and account security events (logins, password and key changes) and are deleted after 14 days. |
| Backups | A copy of the database is made every night, checked for integrity and kept for 7 nights on the same server in Germany. It restores data after a fault or a mistaken change. |
| Breaches | Every incident is recorded in a breach register and handled under section 6. |
| Staff | FormHaven is run by one person, bound to confidentiality by this DPA. |
