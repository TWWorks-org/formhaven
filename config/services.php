<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'cloudflare_email' => [
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'token' => env('CLOUDFLARE_EMAIL_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // DidMyFormSend, the owner's separate monitor (app/Support/DidMyFormSend.php). Used only for a
    // form that holds a key; the reports go to this base URL and never to a host a user typed.
    'didmyformsend' => [
        'url' => env('DIDMYFORMSEND_URL', 'https://didmyformsend.com'),
    ],

    // Self-hosted Umami (app/Support/Umami.php, resources/views/partials/analytics.blade.php).
    // With no website id, no tracker loads and no server-side event is sent.
    'umami' => [
        'website_id' => env('UMAMI_WEBSITE_ID'),
        'script_url' => env('UMAMI_SCRIPT_URL'),
        'collect_url' => env('UMAMI_COLLECT_URL'),
    ],

    // IndexNow key (app/Console/Commands/IndexNowSubmitCommand.php), served at /{key}.txt
    // (routes/web.php). A public domain-ownership token, not a secret. Empty: no key file.
    'indexnow' => [
        'key' => env('INDEXNOW_KEY'),
    ],

    // "Continue with Google" on the login and register pages. The button only
    // shows when both are set. Redirect URI to register in Google Cloud:
    // <APP_URL>/auth/google/callback
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('APP_URL').'/auth/google/callback',
    ],

    // Webhook targets are refused when they point at this server (app/Support/WebhookTarget.php):
    // APP_URL's host by name, every address it resolves to, and these extra IPs or CIDR ranges
    // (comma-separated, IPv4 or IPv6) for addresses of the box that APP_URL does not name.
    // Behind a proxy such as Cloudflare, APP_URL resolves to the proxy's addresses, which other
    // sites share: turn block_app_url_ips off there and list the box's own IPs in blocked_ips.
    'webhooks' => [
        'block_app_url_ips' => (bool) env('WEBHOOK_BLOCK_APP_URL_IPS', true),
        'blocked_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('WEBHOOK_BLOCKED_IPS', ''))))),
    ],

    // OAuth sign-in for the MCP server: redirect hosts of the clients we know. Any other host gets a
    // warning on the consent screen naming where the access would go (resources/views/oauth/authorize.blade.php).
    // claude.ai is the callback Claude's connector docs give.
    'oauth' => [
        'known_redirect_hosts' => array_values(array_filter(array_map(fn ($h) => strtolower(trim($h)), explode(',', (string) env('OAUTH_KNOWN_REDIRECT_HOSTS', 'claude.ai'))))),
    ],

];
