<?php

// Which product this install is (read through app/Support/Mode.php).
//
// hosted: the paid service at formhaven.net. Trial, per-form billing, the marketing site, the
// operator's legal pages. The production server sets FORMHAVEN_MODE=hosted in its .env.
//
// self_hosted (the default, so a fresh clone works): a private form backend. No billing, no trial,
// no limits, no marketing pages, no operator identity, no call to anyone else's server. Accounts can
// be created only while the install has none, unless ALLOW_REGISTRATION=true.
return [

    'mode' => env('FORMHAVEN_MODE', 'self_hosted') === 'hosted' ? 'hosted' : 'self_hosted',

    // Self-hosted only: let anyone create an account after the first one exists.
    'allow_registration' => (bool) env('ALLOW_REGISTRATION', false),

];
