<?php

// Comparison pages, one per rival at /alternatives/{slug}, rendered by
// resources/views/content/alternative.blade.php. Every rival fact needs a
// source link and the date it was read; FormHaven's own figures come from
// config/billing.php at render time, never from here.
return [

    'formspree' => [
        'name' => 'Formspree',
        'title' => 'Formspree alternative with EU data storage',
        'description' => 'Formspree stores submissions in the United States. FormHaven stores them on EU servers in Germany. Prices, limits and where each one is the better pick.',
        'h1' => 'Formspree alternative with the data stored in the EU',
        'intro' => 'Formspree stores submissions in the United States. FormHaven stores them on EU servers in Germany. Here is where each one is the better pick.',
        'checked' => '27 October 2026',
        'sources' => [
            ['Formspree privacy policy', 'https://formspree.io/legal/privacy-policy'],
            ['Formspree plans', 'https://formspree.io/plans'],
        ],
        // [row label, FormHaven, rival]. ":for:N" (the price for N submissions a month), ":min" and ":trial" are filled from config.
        'rows' => [
            ['Where submissions are stored', 'EU: Hetzner, Germany', 'United States'],
            ['Company based in', 'Netherlands', 'United States'],
            ['Free option', ':trial', 'Free plan, 50 submissions a month'],
            ['About 2,000 submissions a month', ':for:2000', '$30 a month (Professional)'],
            ['About 10,000 submissions a month', ':for:10000', '$90 a month (Business, up to 20,000)'],
            ['AI agent setup', 'MCP server and REST API', 'Instructions for agents at formspree.io/ai'],
            ['Search and CSV export of submissions', 'Included', 'From the Personal plan, not on Free'],
            ['Webhooks', 'Signed, included', 'From the Professional plan'],
            ['File uploads, automatic replies', 'Not yet', 'Yes, on paid plans'],
            ['Other apps', 'Slack built in; Make, n8n and Zapier (a paid Zapier plan) through the webhook', 'Native integrations on paid plans'],
        ],
        'pick_us' => [
            'Your clients ask where their visitors\' data is stored',
            'You run forms for many client sites under one login',
            'You need more than 2,000 submissions a month without paying $90',
            'An AI agent builds your sites and should set up the form too',
        ],
        'pick_them' => [
            'Visitors upload files through your form',
            'You want an automatic reply sent to every visitor',
            'You push submissions into other apps with native integrations',
            'You want a free plan for a hobby site with a few messages',
        ],
        'switch' => [
            'remove' => ['<form action="https://formspree.io/f/xyzabcd" method="POST">'],
            'add' => ['<form action="{endpoint}" method="POST">'],
            'keep' => ['<input type="text" name="_gotcha" hidden>'],
            'note' => 'Your HTML stays as it is. Create a form in FormHaven and swap the address in the action. The _gotcha spam trap and the _replyto, _subject and _next fields keep working as they are, and JavaScript gets the same errors array back.',
        ],
    ],

    'formspark' => [
        'name' => 'Formspark',
        'title' => 'Formspark alternative that deletes submissions on schedule',
        'description' => 'Formspark and FormHaven both store submissions in the EU. FormHaven deletes them after the days you set, or keeps none. Prices, limits and the better pick.',
        'h1' => 'Formspark alternative that keeps submissions only as long as you choose',
        'intro' => 'Both store submissions in the EU. FormHaven deletes each one after the days you set per form, or emails it and keeps nothing. Here is where each one is the better pick.',
        'checked' => '3 November 2026',
        'figures_note' => 'Formspark sells one-time bundles, not monthly plans. Its figures',
        'sources' => [
            ['Formspark pricing', 'https://formspark.io/pricing/'],
            ['Formspark DPA', 'https://formspark.io/legal/dpa/'],
            ['Formspark docs', 'https://documentation.formspark.io/troubleshooting/limits-and-plans.html'],
        ],
        // [row label, FormHaven, rival]. ":for:N" (the price for N submissions a month), ":min" and ":trial" are filled from config.
        'rows' => [
            ['Where submissions are stored', 'EU: Hetzner, Germany', 'EU: AWS and Hetzner, Ireland and Germany'],
            ['Company based in', 'Netherlands', 'Belgium'],
            ['How long submissions are kept', 'You set it per form: 1 to 365 days, or none (emailed, then deleted)', 'Until you delete them'],
            ['Visitor IP address', 'Kept only as a one-way hash', 'Kept for 12 months'],
            ['Free option', ':trial', 'Free: 250 submissions, a 10-form limit'],
            ['Cheapest paid option', ':min', '$25 once for 50,000 submissions that never expire, a 100-form limit (listed at $50, on sale)'],
            ['AI agent setup', 'MCP server and REST API', 'REST API and agent instructions; MCP through Zapier or Activepieces'],
            ['Spam filtering', 'Honeypot, allowed sites, spam words, a link filter and an invisible bot check (no captcha, no cookies)', 'Automatic filter, honeypot, spam words, allowed sites and a captcha of your choice'],
            ['Webhooks', 'Signed, included', 'Signed, retried every 5 minutes for about a day'],
            ['Automatic replies', 'Not yet', 'On paid plans'],
            ['Other apps', 'Slack built in; Make, n8n and Zapier (a paid Zapier plan) through the webhook', 'Slack, Zapier, Make, Airtable; Google Sheets and Notion on paid'],
        ],
        'pick_us' => [
            'Your client wants old messages deleted on a schedule, or never stored',
            'You want visitor IP addresses kept only as a hash',
            'An AI agent builds your sites and should set up the form too',
        ],
        'pick_them' => [
            'Your forms get few messages and you would rather pay once than monthly',
            'You want reCAPTCHA, hCaptcha or Turnstile on your form',
            'You want an automatic reply to every visitor, or submissions in Google Sheets or Notion',
            'You want a free plan for a hobby site',
        ],
        'switch' => [
            // Formspark's real endpoint host is left out on purpose: it is common in phishing kits, and
            // Windows Defender quarantines any file carrying it in a form action (Trojan:HTML/Phish.CFN).
            'remove' => ['<form action="YOUR_FORMSPARK_ADDRESS" method="POST">'],
            'add' => ['<form action="{endpoint}" method="POST">'],
            'keep' => ['<input type="text" name="_honeypot" hidden>', '<input type="hidden" name="_redirect" value="/thanks">'],
            'note' => 'Your HTML stays as it is. Create a form in FormHaven and swap the address in the action. The _honeypot trap and the _redirect, _error, _email.subject and _email.replyto fields keep working, relative addresses included. JavaScript that checks response.ok works unchanged. The thank-you address stays clean: the answers are not added to it.',
        ],
    ],

    'web3forms' => [
        'name' => 'Web3Forms',
        'title' => 'Web3Forms alternative with EU storage and unbranded client copies',
        'description' => 'Web3Forms operates from India on AWS. FormHaven stores submissions in the EU and sends your client copies with no branding. Prices, limits and the better pick.',
        'h1' => 'Web3Forms alternative with the data stored in the EU',
        'intro' => 'Web3Forms operates from India and stores submissions on AWS. FormHaven stores every submission on EU servers and sends your client their copy with no FormHaven branding, on every account. Here is where each one is the better pick.',
        'checked' => '10 November 2026',
        'figures_note' => 'Web3Forms prices here are billed yearly, read from its pricing page on 21 November 2026. Its other figures',
        'sources' => [
            ['Web3Forms pricing', 'https://web3forms.com/pricing'],
            ['Web3Forms privacy policy', 'https://web3forms.com/privacy'],
            ['Web3Forms API reference', 'https://docs.web3forms.com/getting-started/api-reference'],
        ],
        // [row label, FormHaven, rival]. ":for:N" (the price for N submissions a month), ":min" and ":trial" are filled from config.
        'rows' => [
            ['Where submissions are stored', 'EU: Hetzner, Germany', 'AWS and Hetzner, in regions its privacy policy does not name'],
            ['Company based in', 'Netherlands', 'India (Web3Creative)'],
            ['Free option', ':trial', 'Free plan, 250 submissions a month'],
            ['About 10,000 submissions a month', ':for:10000', '$12 a month billed yearly, $149 a year (Pro)'],
            ['How long submissions are kept', 'You set it per form: 1 to 365 days, or none (emailed, then deleted)', '30 days on Free, 3 months on Starter, 1 year on Pro and Agency'],
            ['Copies for your client', 'Up to 5 addresses per form, no FormHaven branding, included', '1 recipient on Free, up to 5 on Pro; removing Web3Forms branding is $9 a month extra'],
            ['Spam filtering', 'Honeypot, allowed sites, spam words, a link filter and an invisible bot check, all on our own servers', 'A spam filter that may send the visitor\'s IP and email to CleanTalk and Akismet, hCaptcha, a honeypot; reCAPTCHA and Turnstile on Pro'],
            ['Webhooks', 'Signed, included', 'On paid plans'],
            ['AI agent setup', 'MCP server and REST API', 'A submissions API and docs with an llms.txt'],
            ['File uploads, automatic replies', 'Not yet', 'On Pro and Agency'],
            ['Other apps', 'Slack built in; Make, n8n and Zapier (a paid Zapier plan) through the webhook', 'Google Sheets, Notion, Slack, Discord and Telegram on paid plans'],
        ],
        'pick_us' => [
            'Your clients ask for their visitors\' data to be stored in the EU',
            'You want your client to get each message with no branding, without an add-on',
            'You would rather visitors\' IP and email addresses are not sent to outside spam services',
            'You want to choose how long each form keeps messages, or keep none',
            'An AI agent builds your sites and should set up the form too',
        ],
        'pick_them' => [
            'You want a free plan with 250 submissions a month',
            'Visitors upload files, or you want an automatic reply to every visitor',
            'You send submissions to Google Sheets, Notion, Discord or Telegram',
        ],
        'switch' => [
            'remove' => [
                '<form action="https://api.web3forms.com/submit" method="POST">',
                '<input type="hidden" name="access_key" value="YOUR_ACCESS_KEY">',
                '<input type="hidden" name="subject" value="New message">',
                '<input type="hidden" name="redirect" value="https://yoursite.com/thanks">',
                '<input type="checkbox" name="botcheck" style="display:none">',
            ],
            'add' => [
                '<form action="{endpoint}" method="POST">',
                '<input type="hidden" name="_subject" value="New message">',
                '<input type="hidden" name="_next" value="https://yoursite.com/thanks">',
                '<input type="text" name="_gotcha" style="display:none">',
            ],
            'keep' => ['<input type="email" name="email" required>'],
            'note' => 'Web3Forms\' setting fields have no underscore, so rename them: subject becomes _subject, redirect becomes _next, and the botcheck trap becomes _gotcha. Delete access_key: your FormHaven address already says which form it is. Your own fields stay as they are, and Reply in the email still goes to the email field. Posting from JavaScript? Send an Accept: application/json header and read ok and message from the reply.',
        ],
    ],

    'emailjs' => [
        'name' => 'EmailJS',
        'title' => 'EmailJS alternative that stores your form messages',
        'description' => 'EmailJS sends an email from the browser through your own mail account. FormHaven receives each form message, stores it in the EU and emails it to you.',
        'h1' => 'EmailJS alternative for contact forms',
        'intro' => 'EmailJS sends an email from the browser through a mail account you connect. FormHaven receives each form message, stores it in the EU, filters spam and emails it to you, with no keys in your code. Here is where each one is the better pick.',
        'checked' => '10 November 2026',
        'sources' => [
            ['EmailJS pricing', 'https://www.emailjs.com/pricing/'],
            ['EmailJS email services', 'https://www.emailjs.com/docs/user-guide/connecting-email-services/'],
            ['EmailJS public key FAQ', 'https://www.emailjs.com/docs/faq/is-it-okay-to-expose-my-public-key/'],
        ],
        // [row label, FormHaven, rival]. ":for:N" (the price for N submissions a month), ":min" and ":trial" are filled from config.
        'rows' => [
            ['What it does', 'Receives each form message, stores it and emails it to you', 'Sends an email from a template through a mail account you connect, such as Gmail, Outlook 365 or Fastmail'],
            ['What goes in your code', 'The form\'s address in the action, nothing else', 'A public key, a service ID and a template ID'],
            ['Where messages are kept', 'EU: Hetzner, Germany, for 1 to 365 days per form, or none', 'Email history on its servers: 7 days on Free, 30 days on paid plans; it can be turned off'],
            ['Company', 'Based in the Netherlands', 'EmailJS Pte Ltd; no address on its pricing page'],
            ['Free option', ':trial', 'Free plan, 200 requests a month and 2 templates'],
            ['About 2,000 messages a month', ':for:2000', '$9 a month (Personal, 2,000 requests)'],
            ['Spam filtering', 'Honeypot, allowed sites, spam words, a link filter and an invisible bot check', 'A CAPTCHA you add to the form'],
            ['Copies for your client', 'Up to 5 addresses per form, each confirmed by a link, with no FormHaven branding', 'Whoever your template sends to'],
            ['Searching and exporting old messages', 'Search and CSV export in the dashboard', 'Email history in the dashboard for the days above'],
            ['File attachments, automatic replies', 'Not yet', 'Auto-reply templates; attachments on paid plans, 500 KB to 30 MB'],
        ],
        'pick_us' => [
            'You want every message stored and searchable, not only sent',
            'You would rather keep no keys or template IDs in your code',
            'Your clients ask for the data to be stored in the EU',
            'You want spam filtered before it reaches anyone',
            'An AI agent builds your sites and should set up the form too',
        ],
        'pick_them' => [
            'You want the email to come from your own Gmail or Outlook account',
            'You design the email itself in a template, beyond a contact form',
            'You send attachments or automatic replies',
            'You want a free plan for a few messages a month',
        ],
        'switch' => [
            'remove' => [
                "emailjs.init({ publicKey: 'YOUR_PUBLIC_KEY' });",
                "await emailjs.sendForm('YOUR_SERVICE_ID', 'YOUR_TEMPLATE_ID', '#myForm');",
            ],
            'add' => [
                '<form id="myForm" action="{endpoint}" method="POST">',
                "await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json' }, body: new FormData(form) });",
            ],
            'keep' => ['<input type="email" name="email" required>', '<textarea name="message" required></textarea>'],
            'note' => 'Keep your form and its inputs. Put your FormHaven address in the action, replace the sendForm call with one fetch, and remove the EmailJS script and keys. Every named field appears in the email we send you, and Reply goes to the visitor\'s address. The reply carries ok and message for your success line.',
        ],
    ],

    'formsubmit' => [
        'name' => 'FormSubmit',
        'title' => 'FormSubmit alternative with EU storage and a DPA',
        'description' => 'FormSubmit is free and keeps submissions for 30 days. FormHaven stores them in the EU for as long as you set per form, with a DPA. Limits and the better pick.',
        'h1' => 'FormSubmit alternative with the data stored in the EU',
        'intro' => 'FormSubmit is free and needs no account. FormHaven stores every message on EU servers for as long as you set per form, covers it with a DPA and filters spam without a captcha. Here is where each one is the better pick.',
        'checked' => '10 November 2026',
        'figures_note' => 'FormSubmit is free and lists no paid plan. Its figures',
        'sources' => [
            ['FormSubmit home page', 'https://formsubmit.co/'],
            ['FormSubmit documentation', 'https://formsubmit.co/documentation'],
        ],
        // [row label, FormHaven, rival]. ":for:N" (the price for N submissions a month), ":min" and ":trial" are filled from config.
        'rows' => [
            ['Where submissions are stored', 'EU: Hetzner, Germany', 'No location given in its documentation'],
            ['Company', 'Based in the Netherlands', 'Devro LABS; no address given on the site'],
            ['How long submissions are kept', 'You set it per form: 1 to 365 days, or none (emailed, then deleted)', '30 days, readable through its API up to 5 times a day'],
            ['Price', ':min', 'Free, unlimited forms and submissions'],
            ['Account', 'An account with a dashboard to search and export submissions', 'No account: your email address, or a code it sends you, goes in the form action'],
            ['Spam filtering', 'Honeypot, allowed sites, spam words, a link filter and an invisible bot check (no captcha, no cookies)', 'reCAPTCHA on by default, a honeypot and blocked phrases'],
            ['Copies for your client', 'Up to 5 addresses per form, each confirmed by a link, with no FormHaven branding', 'A _cc field in your HTML'],
            ['Webhooks', 'Signed, included', 'A _webhook field in your HTML'],
            ['AI agent setup', 'MCP server and REST API', 'A JSON endpoint for posting, and an API to read the archive'],
            ['File uploads, automatic replies', 'Not yet', 'Yes: files up to 10 MB in total, replies only with reCAPTCHA on'],
        ],
        'pick_us' => [
            'Your clients ask where their visitors\' data is stored, or need a DPA',
            'You want messages kept longer than 30 days, or never stored',
            'You want spam stopped without asking visitors to solve a reCAPTCHA',
            'You manage forms for many client sites and want one dashboard for all of them',
            'An AI agent builds your sites and should set up the form too',
        ],
        'pick_them' => [
            'You want it free, with no account at all',
            'Visitors upload files through your form',
            'You want an automatic reply sent to every visitor',
        ],
        'switch' => [
            // FormSubmit's real address is left out on purpose, as with Formspark: form-backend hosts are common
            // in phishing kits, and antivirus can quarantine a file carrying one in a form action.
            'remove' => ['<form action="YOUR_FORMSUBMIT_ADDRESS" method="POST">'],
            'add' => ['<form action="{endpoint}" method="POST">'],
            'keep' => ['<input type="text" name="_honey" style="display:none">', '<input type="hidden" name="_next" value="https://yoursite.com/thanks">'],
            'note' => 'Your HTML stays as it is. Create a form in FormHaven and swap the address in the action. The _honey trap and the _next, _subject and _replyto fields keep working. FormSubmit\'s other fields (_captcha, _template, _cc, _autoresponse, _blacklist, _webhook) are ignored and never stored, so they can stay: add your client\'s address, your blocked phrases and your webhook in the form\'s settings instead. Posting from JavaScript? Use the same address, without /ajax/, and send an Accept: application/json header. The reply carries ok and message.',
        ],
    ],

    'formcarry' => [
        'name' => 'Formcarry',
        'title' => 'Formcarry alternative run by an EU company',
        'description' => 'Formcarry stores submissions in Frankfurt but is a US company with remote access from the US. FormHaven is Dutch, hosts in Germany and deletes on your schedule.',
        'h1' => 'Formcarry alternative with an EU company behind it',
        'intro' => 'Formcarry stores submissions in Frankfurt and is run by a US company whose staff may reach the data from the United States. FormHaven is a Dutch company, stores every submission in Germany and deletes it after the days you set per form. Here is where each one is the better pick.',
        'checked' => '12 November 2026',
        'sources' => [
            ['Formcarry pricing', 'https://formcarry.com/pricing'],
            ['Formcarry data processing agreement', 'https://formcarry.com/legal/data-processing-agreement'],
            ['Formcarry docs: what every form needs', 'https://formcarry.com/docs/what-every-form-needs'],
        ],
        // [row label, FormHaven, rival]. ":for:N" (the price for N submissions a month), ":min" and ":trial" are filled from config.
        'rows' => [
            ['Where submissions are stored', 'EU: Hetzner, Germany', 'EU: AWS and DigitalOcean, Frankfurt'],
            ['Company', 'Based in the Netherlands', 'Teijal, Inc. (Delaware, United States); its DPA says staff may access the data from the US'],
            ['How long submissions are kept', 'You set it per form: 1 to 365 days, or none (emailed, then deleted)', 'While your account is active, or until you ask for deletion'],
            ['Free option', ':trial', 'Free Baby plan: 1 form, 50 submissions a month'],
            ['About 2,000 submissions a month', ':for:2000', '$19 a month, or $15 billed yearly (Basic)'],
            ['About 10,000 submissions a month', ':for:10000', '$99 a month, or $80 billed yearly (Premium, up to 30,000)'],
            ['Spam filtering', 'Honeypot, allowed sites, spam words, a link filter and an invisible bot check, all on our own servers', 'A built-in filter, a _gotcha honeypot, and reCAPTCHA, hCaptcha or Turnstile; its DPA lists Akismet (United States) as a subprocessor'],
            ['AI agent setup', 'MCP server and REST API', 'MCP server and REST API'],
            ['File uploads, automatic replies', 'Not yet', 'Uploads on paid plans, and automatic replies'],
            ['Other apps', 'Slack built in; Make, n8n and Zapier (a paid Zapier plan) through the webhook', 'Webhooks and Zapier'],
        ],
        'pick_us' => [
            'Your clients want their visitors\' data held by an EU company, not reachable from the US',
            'You want each form to delete messages on a schedule, or keep none',
            'You need about 10,000 submissions a month: :price:10000 against $99',
            'You want spam filtered without a captcha and without sending visitor data to an outside service',
        ],
        'pick_them' => [
            'Visitors upload files through your form',
            'You want an automatic reply sent to every visitor',
            'You want a free plan for one form with a few messages',
            'You want 500 submissions a month for $6',
        ],
        'switch' => [
            // Formcarry's real address is left out on purpose, as with Formspark and FormSubmit: form-backend hosts are
            // common in phishing kits, and antivirus can quarantine a file carrying one in a form action.
            'remove' => ['<form action="YOUR_FORMCARRY_ADDRESS" method="POST">'],
            'add' => ['<form action="{endpoint}" method="POST">'],
            'keep' => ['<input type="text" name="_gotcha" style="display:none">', '<input type="hidden" name="_next" value="https://yoursite.com/thanks">'],
            'note' => 'Your HTML stays as it is. Create a form in FormHaven and swap the address in the action. The _gotcha honeypot keeps working, and so does _next. Posting from JavaScript? Keep the Accept: application/json header; the reply carries ok and message, so check response.ok rather than code.',
        ],
    ],

    'basin' => [
        'name' => 'Basin',
        'title' => 'Basin alternative with submissions stored in Germany',
        'description' => 'Basin is a Canadian company and lists AWS in the US among its hosts. FormHaven is Dutch and stores every submission in Germany. Prices, limits and the better pick.',
        'h1' => 'Basin alternative with the data stored in Germany',
        'intro' => 'Basin is run from Canada and does not say which country holds your submissions; its hosts include AWS in the United States. FormHaven is a Dutch company and stores every submission in Germany, for the days you set per form. Here is where each one is the better pick.',
        'checked' => '14 November 2026',
        'figures_note' => 'Basin shows some prices billed yearly only. Its figures',
        'sources' => [
            ['Basin pricing', 'https://usebasin.com/pricing'],
            ['Basin privacy policy', 'https://usebasin.com/privacy'],
            ['Basin GDPR and subprocessors', 'https://usebasin.com/gdpr'],
            ['Basin docs: spam filters', 'https://docs.usebasin.com/spam-protection/advanced-filters/'],
        ],
        // [row label, FormHaven, rival]. ":for:N" (the price for N submissions a month), ":min" and ":trial" are filled from config.
        'rows' => [
            ['Where submissions are stored', 'EU: Hetzner, Germany', 'Not stated; its hosts are AWS (United States), DigitalOcean (Canada) and Hetzner (EU)'],
            ['Company', 'Based in the Netherlands', 'Moonshot Ventures Inc. (British Columbia, Canada)'],
            ['How long submissions are kept', 'You set it per form: 1 to 365 days, or none (emailed, then deleted)', 'You set it per form, within the plan: 30 days on Free, 365 on Starter, no limit from Growth'],
            ['Free option', ':trial', 'Free plan: 1 form, 50 submissions a month; 14-day trial on paid plans'],
            ['About 1,000 submissions a month', ':for:1000', '$24.17 a month billed yearly (Growth)'],
            ['About 5,000 submissions a month', ':for:5000', '$40.83 a month, or $30.62 billed yearly (Pro)'],
            ['About 25,000 submissions a month', ':for:25000', '$108.33 a month, or $81.25 billed yearly (Agency)'],
            ['Spam filtering', 'Honeypot, allowed sites, spam words, a link filter and an invisible bot check, all on our own servers', 'An automatic spam check, honeypot, allowed domains, email and country filters, and a captcha of your choice; its newest spam check sends message content to OpenAI'],
            ['AI agent setup', 'MCP server and REST API; an agent can start a form before you have an account', 'MCP from Starter, REST API from Growth'],
            ['File uploads', 'Not yet', 'Yes, 100 MB to 50 GB of storage by plan'],
        ],
        'pick_us' => [
            'Your clients want their visitors\' data stored in the EU by an EU company',
            'You need about 5,000 submissions a month: :price:5000 against $40.83',
            'You want spam filtered without message content going to an AI service',
            'An AI agent builds your sites and should set up the form before anyone signs up',
        ],
        'pick_them' => [
            'Visitors upload files through your form',
            'You want a free plan for one small form',
            'You want country, language or burner-email filters, or a captcha',
        ],
        'switch' => [
            // Basin's real address is left out on purpose, as with the other rivals: form-backend hosts are
            // common in phishing kits, and antivirus can quarantine a file carrying one in a form action.
            'remove' => ['<form action="YOUR_BASIN_ADDRESS" method="POST">'],
            'add' => ['<form action="{endpoint}" method="POST">'],
            'keep' => ['<input type="hidden" name="_gotcha">'],
            'note' => 'Your HTML stays as it is. Create a form in FormHaven and swap the address in the action. Basin\'s default _gotcha honeypot keeps working. Basin sets the thank-you page in the form\'s settings, so set yours in FormHaven\'s form settings too, or add a hidden _next field. Posting from JavaScript? Keep the Accept: application/json header; the reply carries ok and message.',
        ],
    ],

];
