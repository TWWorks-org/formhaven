<?php

// Single source of truth for the price, the limits and the trial. The pricing page,
// the billing page, every limit check and the monthly extra-usage charge read from here.
//
// The price is per form (since 2026-11-20), graduated since 2026-11-21:
// the first full_price_forms forms cost monthly_eur (yearly_eur) each, every form after them
// lower_monthly_eur (lower_yearly_eur). Every form adds the same submissions to the pool,
// whatever it costs. One tiered Stripe price per interval (graduated tiers) whose quantity
// follows the account's forms, never below min_forms; app/Support/Bill.php does the same sum
// for the pages, the billing screen and the admin income. A month whose real submissions,
// in thousands, beat the most units it had that month is charged the difference at
// extra_eur a thousand once the month ends (app/Console/Commands/ChargeExtraSubmissions.php):
// on the next regular invoice for a monthly plan, on its own invoice for a yearly one.
// So every started thousand of submissions is paid for, by a form or by an extra 1,000.
return [

    'trial_days' => 14,

    // A trial is not paid for, so it keeps a hard cap: this many forms, and the usual
    // allowance per form with emails pausing past the grace share.
    'trial_forms' => 25,

    // The business's own accounts (comma-separated in OWNER_EMAILS in the server's .env): no
    // bill, no form cap and owner_submissions a month, so the forms on the owner's other
    // sites never pause.
    'owner_emails' => array_values(array_filter(array_map(fn ($e) => strtolower(trim($e)), explode(',', (string) env('OWNER_EMAILS', ''))))),
    'owner_submissions' => 50000,

    // Turn on once Stripe Tax is registered for the business.
    'automatic_tax' => env('STRIPE_AUTOMATIC_TAX', false),

    'per_form' => [
        // The price of each of the first full_price_forms forms.
        'monthly_eur' => 1,
        'yearly_eur' => 10,
        'full_price_forms' => 10,
        // The price of every form from full_price_forms + 1 on.
        'lower_monthly_eur' => 0.5,
        'lower_yearly_eur' => 5,
        // The price of each started 1,000 submissions past the pool (STRIPE_PRICE_EXTRA_SUBMISSIONS).
        // Never above lower_monthly_eur: a form adds 1,000 to the pool too, so a dearer extra would
        // make empty forms the cheaper way to buy submissions (BillingTest locks this).
        'extra_eur' => 0.5,
        // The smallest bill: this many forms' worth, so a card fee does not eat it.
        'min_forms' => 5,
        // Each paid unit (a form, or a thousand extra submissions) adds this many real
        // submissions a month to the account's pool. Spam never counts.
        'submissions' => 1000,
        'price_monthly' => env('STRIPE_PRICE_FORM_MONTHLY'),
        'price_yearly' => env('STRIPE_PRICE_FORM_YEARLY'),
        // A one-off price of extra_eur ("Extra 1,000 submissions"), billed per started thousand past the pool.
        'price_extra' => env('STRIPE_PRICE_EXTRA_SUBMISSIONS'),
        // The Stripe quantity is synced this long after a form is added or deleted
        // (app/Jobs/SyncFormQuantity.php), so a form made and removed within it costs nothing.
        'sync_delay_minutes' => 60,
    ],

    // Past the trial's monthly submissions, emails keep going for this share of the
    // limit before they stop, so one busy week does not silently cut off leads.
    // Paid accounts never stop: past their pool they pay per extra thousand.
    'email_grace' => 0.10,

    // The owner is emailed once per month as usage crosses each of these percentages
    // of the submissions the account's units include.
    'usage_notices' => [80, 100],

];
