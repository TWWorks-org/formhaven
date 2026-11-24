<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Mailer
    |--------------------------------------------------------------------------
    |
    | This option controls the default mailer that is used to send all email
    | messages unless another mailer is explicitly specified when sending
    | the message. All additional mailers can be configured within the
    | "mailers" array. Examples of each type of mailer are provided.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Mailer Configurations
    |--------------------------------------------------------------------------
    |
    | Here you may configure all of the mailers used by your application plus
    | their respective settings. Several examples have been configured for
    | you and you are free to add your own as your application requires.
    |
    | Laravel supports a variety of mail "transport" drivers that can be used
    | when delivering an email. You may specify which one you're using for
    | your mailers below. You may also add additional mailers if needed.
    |
    | Supported: "smtp", "sendmail", "mailgun", "ses", "ses-v2",
    |            "postmark", "resend", "log", "array",
    |            "failover", "roundrobin"
    |
    */

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 2525),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'timeout' => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN', parse_url(env('APP_URL', 'http://localhost'), PHP_URL_HOST)),
        ],

        'ses' => [
            'transport' => 'ses',
        ],

        'cloudflare' => [
            'transport' => 'cloudflare',
        ],

        'postmark' => [
            'transport' => 'postmark',
            // 'message_stream_id' => env('POSTMARK_MESSAGE_STREAM_ID'),
            // 'client' => [
            //     'timeout' => 5,
            // ],
        ],

        'resend' => [
            'transport' => 'resend',
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => [
                'smtp',
                'log',
            ],
        ],

        'roundrobin' => [
            'transport' => 'roundrobin',
            'mailers' => [
                'ses',
                'postmark',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "From" Address
    |--------------------------------------------------------------------------
    |
    | You may wish for all emails sent by your application to be sent from
    | the same address. Here you may specify a name and address that is
    | used globally for all emails that are sent by your application.
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name' => env('MAIL_FROM_NAME', 'Example'),
    ],

    /*
     * Daily mail budgets, counted per UTC day (app/Support/MailBudget.php). Your mail provider has a
     * daily quota, and anyone who copies a form's address can post to it, so every kind of mail a
     * stranger can cause has a ceiling. Set them so that together they stay well under your
     * provider's daily quota. A submission email over a ceiling is stored and held, not lost: the
     * owner can send it again later. An account email over its ceiling is not sent, and the page
     * says to try again tomorrow (app/Support/AccountMail.php).
     */
    'budget' => [
        // Every email about a submission (first send and Send again), across all forms.
        'notifications_per_day' => (int) env('MAIL_BUDGET_NOTIFICATIONS', 70),
        // The same, for one form: one copied form address cannot use the whole pool above.
        'per_form_per_day' => (int) env('MAIL_BUDGET_PER_FORM', 25),
        // The Monday summary to owners and the weekly count to recipients, together.
        'summaries_per_day' => (int) env('MAIL_BUDGET_SUMMARIES', 40),
        // Abuse-report mail: our copy first, then the reporter's receipt (2 per report).
        'abuse_reports_per_day' => (int) env('MAIL_BUDGET_ABUSE_REPORTS', 6),
        // Notices that a limit above was reached: one per form owner and one to the operator a day at most.
        'notices_per_day' => (int) env('MAIL_BUDGET_NOTICES', 6),
        // Account mail (address confirmation, password reset, the email-change notice, the deletion
        // link) for accounts that never proved their address: new signups, which anyone can make.
        'signup_per_day' => (int) env('MAIL_BUDGET_SIGNUP', 10),
        // The same mail for accounts that did. Signups cannot spend it, so a reset always has room.
        'account_per_day' => (int) env('MAIL_BUDGET_ACCOUNT', 20),
    ],

];
