<?php

namespace App\Actions;

use App\Models\Form;
use App\Models\Submission;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Block this sender": moves one message to spam and puts who sent it on the form's spam words,
 * so the next message from the same company goes to spam too, whatever name it signs with.
 * Used by the link in the owner's email and the dashboard, so both block exactly the same thing.
 *
 * The block is a spam word ("@company.com", or the full address at a shared mail provider), so the
 * API and MCP already handle it. The dashboard lists these entries apart, under Settings, with Unblock.
 */
class BlockSender
{
    /**
     * Mail providers that many unrelated people share: blocking the domain would block real
     * visitors, so only the one address goes on the list.
     */
    public const SHARED_DOMAINS = [
        'gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'yahoo.com',
        'icloud.com', 'me.com', 'mac.com', 'aol.com', 'mail.com', 'gmx.com', 'gmx.net', 'gmx.de', 'gmx.at',
        'web.de', 'freenet.de', 't-online.de', 'proton.me', 'protonmail.com', 'pm.me', 'tutanota.com',
        'yandex.com', 'yandex.ru', 'mail.ru', 'zoho.com', 'hotmail.nl', 'live.nl', 'outlook.de', 'hotmail.de',
        'yahoo.de', 'yahoo.fr', 'hotmail.fr', 'orange.fr', 'free.fr', 'laposte.net', 'wanadoo.fr',
        'libero.it', 'ziggo.nl', 'kpnmail.nl', 'planet.nl', 'home.nl', 'telenet.be', 'skynet.be', 'qq.com',
    ];

    /** What goes on the spam words for this address: "@company.com", the address itself at a shared provider, or null. */
    public static function entryFor(?string $email): ?string
    {
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $email = Str::lower(trim($email));
        $domain = Str::after($email, '@');
        $entry = in_array($domain, self::SHARED_DOMAINS, true) ? $email : '@'.$domain;

        return mb_strlen($entry) <= FormSpamSettings::MAX_WORD_LENGTH ? $entry : null;
    }

    /** A spam word that blocks a sender ("@company.com" or one address), not an ordinary word. */
    public static function isSender(string $word): bool
    {
        return str_contains($word, '@');
    }

    /**
     * Moves the message to spam and blocks $entry (worked out when the email was sent, because a form
     * that keeps nothing has wiped the answers since). Returns what happened, in a sentence for the owner.
     */
    public function block(Submission $submission, ?string $entry): string
    {
        if (! $submission->spam) {
            // Spam is never billed, so it comes off the month's count.
            \App\Models\MonthlyUsage::add($submission->form->user_id, $submission->created_at, -1);
        }
        $submission->forceFill(['spam' => true, 'spam_reason' => 'marked'])->save();

        if ($entry === null) {
            return 'Moved to spam. It had no email address, so there is no sender to block.';
        }

        $form = $submission->form;
        $words = $form->spam_words ?: [];

        if (in_array($entry, $words, true)) {
            return 'Moved to spam. '.$entry.' was already blocked on '.$form->name.'.';
        }

        try {
            app(FormSpamSettings::class)->update($form, ['spam_words' => [...$words, $entry]]);
        } catch (ValidationException) {
            return 'Moved to spam, but '.$entry.' was not blocked: '.$form->name.' already has '.FormSpamSettings::MAX_WORDS.' spam words and blocked senders. Remove some under Settings.';
        }

        return 'Moved to spam. New messages from '.$entry.' on '.$form->name.' now go to spam too. Unblock it under Settings, or press Not spam on the message.';
    }

    /**
     * Unblock in the dashboard's list: takes one blocked sender off the form's spam words and leaves
     * everything else. Messages it already sent stay where they are. Returns a sentence for the owner.
     */
    public function unblockEntry(Form $form, string $entry): string
    {
        $words = $form->spam_words ?: [];

        if (! self::isSender($entry) || ! in_array($entry, $words, true)) {
            return $entry.' was not blocked on '.$form->name.'.';
        }

        $form->update(['spam_words' => array_values(array_diff($words, [$entry])) ?: null]);

        return 'Unblocked '.$entry.'. New messages from it reach your inbox again. Messages it already sent stay in spam: press Not spam on one to move it back.';
    }

    /** The undo: the message goes back to the inbox and its sender comes off the spam words. */
    public function unblock(Submission $submission): void
    {
        if ($submission->spam) {
            \App\Models\MonthlyUsage::add($submission->form->user_id, $submission->created_at, 1);
        }
        $submission->forceFill(['spam' => false, 'spam_reason' => null])->save();

        $entry = self::entryFor($submission->replyToAddress());
        $form = $submission->form;

        if ($entry !== null && in_array($entry, $form->spam_words ?: [], true)) {
            $form->update(['spam_words' => array_values(array_diff($form->spam_words, [$entry])) ?: null]);
        }
    }
}
