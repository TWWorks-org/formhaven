<?php

namespace App\Support;

/**
 * Text someone else typed, made safe for a Markdown email. {{ }} escapes HTML, but the
 * mail's CommonMark pass still turns "[Pay here](https://evil.example)" into a link and
 * "![](...)" into an image, inside an email that comes from us. Links and images both
 * need a "[", so escaping the brackets (and the backslash, so it cannot cancel ours)
 * is enough. "<" autolinks are already harmless: {{ }} turns "<" into an entity.
 * Nothing else is escaped, because Laravel sends the Markdown source as the text part
 * and every extra backslash would show up there.
 */
class MarkdownText
{
    public static function escape(?string $text): string
    {
        return preg_replace('/[\\\\\[\]]/', '\\\\$0', (string) $text);
    }
}
