<?php

namespace App\Actions;

use App\Exceptions\SubmissionRefused;
use App\Jobs\DeliverWebhook;
use App\Mail\SubmissionReceived;
use App\Models\DeliveryFailure;
use App\Models\Form;
use App\Models\Submission;
use App\Support\BotCheck;
use App\Support\MailBudget;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Everything that happens to one submission: plan checks, field rules, spam,
 * storage, the owner's email and usage notices. The public endpoint and the
 * dashboard's test button both run through here, so a test proves the real path.
 */
class ReceiveSubmission
{
    /** Hidden field humans never fill; bots usually do. Same name Formspree uses, so existing forms keep working. */
    public const HONEYPOT = '_gotcha';

    /** Every honeypot name we read: Formspree's, Formspark's _honeypot and FormSubmit's _honey, so a form moved from any of them keeps its trap. */
    public const HONEYPOTS = ['_gotcha', '_honeypot', '_honey'];

    /**
     * Formspark's names for our control fields, so a form moved from Formspark keeps working.
     * PHP turns the "." in a posted field name into "_", so both spellings arrive.
     */
    public const ALIASES = [
        '_email.subject' => '_subject', '_email_subject' => '_subject',
        '_email.replyto' => '_replyto', '_email_replyto' => '_replyto',
    ];

    /** Control fields that carry the visitor's own input, so they are kept with the answers. Formspree names. */
    public const KEPT_CONTROL_FIELDS = ['_replyto', '_subject'];

    /** Fields checked as an email address when filled in. */
    public const EMAIL_FIELDS = ['email', '_replyto'];

    public const MAX_FIELDS = 50;

    public const MAX_VALUE_LENGTH = 5000;

    /** More links than this in one submission reads as link spam; a real enquiry rarely carries more than one or two. */
    public const MAX_LINKS = 4;

    /**
     * Why the answers themselves read as spam, or null: forum link code, more links than
     * MAX_LINKS, or one of the form's spam words ("spam_word:<word>").
     *
     * @param  array<string, string|array<string>>  $data
     */
    public static function contentRefusal(Form $form, array $data): ?string
    {
        $text = collect($data)->flatten()->implode("\n");

        if (preg_match('~\[url[=\]]|<a\s+href~i', $text)) {
            return 'link_markup';
        }

        if (preg_match_all('~https?://~i', $text) > self::MAX_LINKS) {
            return 'links';
        }

        // Runs of spaces and line breaks count as one space, as they do in the stored words.
        $haystack = preg_replace('/\s+/u', ' ', Submission::searchable($text));
        foreach ($form->spam_words ?: [] as $word) {
            if (str_contains($haystack, Submission::searchable($word))) {
                return 'spam_word:'.$word;
            }
        }

        return null;
    }

    private static function utf8(string $text): string
    {
        return mb_scrub($text, 'UTF-8');
    }

    /**
     * @param  array<string, mixed>  $input  the posted fields, control fields included
     * @param  array{ip?: ?string, user_agent?: ?string, referer?: ?string, origin?: ?string, dashboard_test?: bool}  $meta
     *
     * @throws SubmissionRefused
     */
    public function handle(Form $form, array $input, array $meta = []): Submission
    {
        $owner = $form->user;

        if ($owner->plan() === null) {
            throw new SubmissionRefused(402, 'This form is paused because the site owner\'s FormHaven subscription has ended. Please contact them another way.', 'FORM_PAUSED');
        }

        if (! $form->withinPlan()) {
            throw new SubmissionRefused(402, 'This form is paused because the site owner\'s FormHaven trial covers fewer forms. Please contact them another way.', 'FORM_PAUSED');
        }

        foreach (self::ALIASES as $alias => $field) {
            if (filled($input[$alias] ?? null) && blank($input[$field] ?? null)) {
                $input[$field] = $input[$alias];
            }
        }

        // Control fields start with an underscore and are not stored, except the
        // few that hold what the visitor typed (reply-to address, subject).
        $fields = collect($input)
            ->except(['_token'])
            ->reject(fn ($value, $key) => str_starts_with((string) $key, '_') && ! in_array($key, self::KEPT_CONTROL_FIELDS, true));

        if ($fields->except(self::KEPT_CONTROL_FIELDS)->isEmpty()) {
            throw new SubmissionRefused(422, 'The submission had no fields. Give each input a name attribute.', 'EMPTY');
        }

        if ($fields->count() > self::MAX_FIELDS) {
            throw new SubmissionRefused(422, 'Too many fields: the limit is '.self::MAX_FIELDS.' per submission.', 'TOO_MANY_FIELDS');
        }

        $data = $fields->map(fn ($value, $key) => match (true) {
            is_array($value) => array_map(fn ($v) => Str::limit((string) $v, self::MAX_VALUE_LENGTH, ''), $value),
            // A phone keyboard often adds a space after an address; that is no reason to refuse it.
            in_array($key, self::EMAIL_FIELDS, true) => Str::limit(trim((string) $value), self::MAX_VALUE_LENGTH, ''),
            default => Str::limit((string) $value, self::MAX_VALUE_LENGTH, ''),
        })
            // Bytes that are not UTF-8 would make every JSON encode below throw, after the row
            // exists but before the email and the wipe: repair them instead.
            ->mapWithKeys(fn ($value, $key) => [self::utf8((string) $key) => is_array($value) ? array_map(self::utf8(...), $value) : self::utf8($value)])
            ->all();

        // A person who mistyped their address is told, because otherwise the owner cannot reply to
        // them. Checked before any spam filter runs, so the answer depends only on what was typed:
        // spam and real mail with the same address get the same 422, and a bot learns nothing about
        // whether it passed the filters. It also comes before the bot check, so the visitor's solved
        // puzzle is not used up and they can send again once the address is fixed.
        foreach (self::EMAIL_FIELDS as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && $value !== '' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new SubmissionRefused(422, 'The email address "'.Str::limit($value, 60).'" is not valid.', 'TYPE_EMAIL', $key);
            }
        }

        // The dashboard's test button posts from our own site without the bot check, so only the honeypot applies to it.
        $spamReason = match (true) {
            collect(self::HONEYPOTS)->contains(fn ($name) => filled($input[$name] ?? null)) => 'honeypot',
            $meta['dashboard_test'] ?? false => null,
            default => $form->senderRefusal($meta['origin'] ?? null, $meta['referer'] ?? null)
                ?? ($form->bot_check && ! BotCheck::claim($form, $input[BotCheck::FIELD] ?? null) ? 'bot_check' : null)
                ?? self::contentRefusal($form, $data),
        };
        // Spam is never refused, so bots learn nothing.
        $spam = $spamReason !== null;

        // On a trial, past the included submissions plus the grace share, we still keep the message
        // (the visitor did nothing wrong) but stop emailing until the owner subscribes or the month
        // rolls over. A paying account never stops: it pays per extra thousand (config/billing.php).
        $emailsPaused = $owner->emailsPaused();

        // Spam is never emailed, so on a form that stores nothing honeypot spam is not kept to begin with.
        // Every other kind is kept, for the form's retention days: it may be a real visitor (on a site the
        // owner forgot to list, without JavaScript, or using a spam word), and the spam list is the only
        // place they could find it.
        $keep = $form->store_submissions || ! $spam || $spamReason !== 'honeypot';

        $submission = $form->submissions()->create([
            'data' => $keep ? $data : [],
            'spam' => $spam,
            'spam_reason' => $spamReason,
            'ip_hash' => hash_hmac('sha256', (string) ($meta['ip'] ?? ''), config('app.key')),
            'user_agent' => $keep ? Str::limit((string) ($meta['user_agent'] ?? ''), 500, '') : null,
            'referer' => $keep ? Str::limit((string) ($meta['referer'] ?? ''), 2000, '') : null,
        ]);

        // Built now, before a form that keeps nothing wipes the answers after its email.
        // A webhook problem is reported, never allowed to skip the email or the wipe.
        if (! $spam && $form->webhook_url) {
            try {
                $body = json_encode($submission->setRelation('form', $form)->webhookPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
                DeliverWebhook::dispatch($form->id, $body);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // One email per target. The count goes on the row first, so a form that stores
        // nothing wipes the answers after the last of them, not the first.
        $targets = ! $spam && ! $emailsPaused ? $form->mailTargets() : [];

        if ($targets !== []) {
            $submission->forceFill(['emails_pending' => count($targets)])->save();

            // Every hold is recorded before the first email is queued, so a worker that finishes an
            // email straight away already sees which of its siblings were held (DidMyFormSend's report
            // waits for the last email of a submission and counts held ones as done).
            $send = [];
            foreach ($targets as $recipient) {
                $email = $recipient?->email ?? $owner->email;

                // Past a daily mail limit the email is held, not lost: it stays pending (so a form
                // that keeps nothing keeps these answers) and Send again delivers it later. The
                // visitor's answer is the same either way.
                if ($held = MailBudget::takeForForm($form)) {
                    DeliveryFailure::hold($form, $submission, $recipient, $email, $held);

                    continue;
                }

                $send[] = [$email, $recipient];
            }

            foreach ($send as [$email, $recipient]) {
                Mail::to($email)->queue(new SubmissionReceived($submission, $recipient));
            }
        }

        if (! $spam) {
            // A test from the dashboard proves the path, not that visitors write in, so it is neither counted nor billed.
            if (! ($meta['dashboard_test'] ?? false)) {
                // With the form units now, so the month's pool keeps the most forms it had.
                \App\Models\MonthlyUsage::add($owner->id, $submission->created_at, 1, $owner->billedUnits());
                $form->noteMessage($submission->created_at);
            }

            $owner->sendUsageNoticeIfDue();
        }

        return $submission;
    }
}
