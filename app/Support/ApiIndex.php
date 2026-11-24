<?php

namespace App\Support;

/**
 * Every REST route an agent can call, as the API index, the reference guide and llms.txt list it.
 * A test compares this with the router, so a new route cannot ship undocumented.
 */
class ApiIndex
{
    public const ENDPOINTS = [
        'POST /api/v1/start' => 'Reserve a form without an account. Returns the endpoint, a link for the site owner and a setup key.',
        'GET /api/v1/start/{id}' => 'With the setup key: whether the site owner has activated the form yet.',
        'GET /api/v1/forms' => 'Every form on the account.',
        'POST /api/v1/forms' => 'Create a form.',
        'GET /api/v1/forms/{id}' => 'One form with its settings and recipients.',
        'PATCH /api/v1/forms/{id}' => 'Change any setting: name, website, thank-you page, retention, store none, webhook, allowed sites, DidMyFormSend key, emails, spam.',
        'DELETE /api/v1/forms/{id}' => 'Delete a form and its submissions. Send {"confirm": "<the form\'s name>"}. Full API keys only. The bill counts one form fewer from that day.',
        'GET /api/v1/forms/{id}/submissions' => 'Submissions, newest first; ?spam=1 for the spam list.',
        'GET /api/v1/forms/{id}/privacy-text' => 'The privacy-policy paragraph for the site; ?lang=en, nl or de.',
        'POST /api/v1/forms/{id}/recipients' => 'Email a form\'s submissions to another address, after it confirms.',
        'POST /api/v1/forms/{id}/recipients/{email}/resend' => 'Send the confirmation link again.',
        'DELETE /api/v1/forms/{id}/recipients/{email}' => 'Stop emailing that address.',
        'POST /api/v1/forms/{id}/deliveries/retry' => 'Send the messages that did not arrive again, once the address works. The health block of the form lists them.',
    ];

    /** @return list<string> each route, then what it does indented below it, wrapped to fit a code box */
    public static function lines(): array
    {
        return self::block(self::ENDPOINTS);
    }

    /**
     * @param  array<string, string>  $items  heading => text
     * @return list<string>
     */
    public static function block(array $items): array
    {
        $lines = [];
        foreach ($items as $heading => $text) {
            $lines[] = $heading;
            foreach (explode("\n", wordwrap($text, 64)) as $line) {
                $lines[] = '  '.$line;
            }
            $lines[] = '';
        }

        return array_slice($lines, 0, -1);
    }
}
