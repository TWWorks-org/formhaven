<?php

namespace App\Support;

use App\Models\Submission;
use Illuminate\Support\Str;

/**
 * A Slack incoming webhook rejects our JSON (it needs a "text" field), so for a hooks.slack.com
 * address the same payload is rewritten as a readable message. Visitor text is escaped: without
 * that, a visitor could type <!channel> into the form and ping the owner's whole Slack channel.
 */
class SlackMessage
{
    /** Slack cuts a message at 40,000 characters; stay well under it and say so when trimmed. */
    private const MAX = 38000;

    public static function isSlack(WebhookTarget $target): bool
    {
        return $target->host === 'hooks.slack.com';
    }

    public static function fromWebhookBody(string $body): string
    {
        $payload = json_decode($body, true) ?: [];
        $fields = (array) ($payload['fields'] ?? []);
        $form = self::escape((string) ($payload['form']['name'] ?? 'your form'));

        $subject = trim(preg_replace('/\s+/', ' ', (string) ($fields['_subject'] ?? '')));
        $heading = ($payload['test'] ?? false)
            ? "*Test event from FormHaven: {$form}*"
            : '*'.($subject !== '' ? self::escape(Str::limit($subject, 150)) : "New submission: {$form}").'*';

        $lines = [$heading];
        if (! empty($payload['page'])) {
            $lines[] = 'Sent from '.self::escape((string) $payload['page']);
        }
        $lines[] = '';

        foreach ($fields as $key => $answer) {
            if ($key === '_subject') {
                continue;
            }
            $value = is_array($answer) ? implode(', ', array_map('strval', $answer)) : (string) $answer;
            $value = str_contains($value, "\n") ? "\n".$value : $value;
            $lines[] = '*'.self::escape(Submission::fieldLabel((string) $key)).':* '.self::escape($value);
        }

        if ($fields === []) {
            $lines[] = '_This form keeps no answers and sent none._';
        }

        $text = implode("\n", $lines);
        if (mb_strlen($text) > self::MAX) {
            $text = mb_substr($text, 0, self::MAX)."\n\n_Trimmed: the full submission is in your FormHaven dashboard._";
        }

        return json_encode(['text' => $text], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /** Slack's own escaping rule: only &, < and > are control characters in message text. */
    private static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
