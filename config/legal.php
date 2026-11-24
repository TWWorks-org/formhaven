<?php

// Who runs this install, as printed in the privacy policy, terms, DPA and the privacy text each
// form generates. Every operator fact comes from env with no default in code, so a self-hosted
// clone never names someone else's company or country. The hosted
// service sets them on its server; a privacy policy without the controller's legal identity
// does not meet GDPR Art. 13.
return [
    'entity' => env('LEGAL_ENTITY'),
    // Optional: left out of the footer and legal pages when empty.
    'address' => env('LEGAL_ADDRESS'),
    'kvk' => env('LEGAL_KVK'),
    // Privacy and legal inbox. Set on the server; an install without it uses its sending address.
    'email' => env('LEGAL_EMAIL') ?: env('MAIL_FROM_ADDRESS', 'hello@example.com'),
    // Support inbox shown on public pages, in security.txt and in delivery-problem advice.
    // Set on the server; an install without it uses its sending address.
    'support_email' => env('SUPPORT_EMAIL') ?: env('MAIL_FROM_ADDRESS', 'hello@example.com'),
    'updated' => '2026-11-24',
    // Where the business is run from and where submissions are stored. The privacy text each form
    // generates reads these; a test keeps them equal to resources/legal/dpa.md and subprocessors.md.
    // The Dutch and German values fall back to the English one. The storage country in copy
    // ("stored in Germany", "in the EU") is the last part of the English place (App\Support\Operator).
    'country' => [
        'en' => env('LEGAL_COUNTRY_EN'),
        'nl' => env('LEGAL_COUNTRY_NL') ?: env('LEGAL_COUNTRY_EN'),
        'de' => env('LEGAL_COUNTRY_DE') ?: env('LEGAL_COUNTRY_EN'),
    ],
    'hosting' => [
        'provider' => env('LEGAL_HOSTING_PROVIDER'),
        'place' => [
            'en' => env('LEGAL_HOSTING_PLACE_EN'),
            'nl' => env('LEGAL_HOSTING_PLACE_NL') ?: env('LEGAL_HOSTING_PLACE_EN'),
            'de' => env('LEGAL_HOSTING_PLACE_DE') ?: env('LEGAL_HOSTING_PLACE_EN'),
        ],
    ],
    // Who sends the emails (the mail provider), as named in the privacy text.
    'mailer' => env('LEGAL_MAILER'),
    // Nights of database copies kept on the same server: KEEP in the hosted backup script.
    // LegalComplianceTest fails when the two differ.
    'backup_days' => 7,
];
