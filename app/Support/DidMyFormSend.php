<?php

namespace App\Support;

use App\Jobs\SendMonitorHeartbeat;
use App\Models\DeliveryFailure;
use App\Models\Form;
use App\Models\FormRecipient;
use App\Models\Submission;

/**
 * Reports to DidMyFormSend (didmyformsend.com), the owner's separate monitor, whether a form's
 * notification emails went out. Opt-in per form: with no key saved on the form nothing is built,
 * queued or sent, to anyone.
 *
 * One heartbeat per submission, not per email. A form with several
 * targets sends one email per target, and on the monitor a "sent" that arrives last makes it
 * healthy again, so one heartbeat per email would let a client's working inbox hide the owner's
 * bounced one. Instead every final outcome (an email the transport accepted, or one that failed
 * after its last try) checks whether the submission is settled, and the one that settles it sends:
 *  - "failed", with the worst failure kind, if any email of it failed;
 *  - "sent" if none failed and this last one went out.
 * Settled means every email still counted in emails_pending has a DeliveryFailure row: a sent
 * email takes itself off emails_pending, a failed or held one leaves a row and stays counted.
 * Mail held by a daily budget (held_form, held_all) is not a failure of the owner's mail setup:
 * it is never reported, and a submission whose every email was held sends nothing.
 * "Send again" settles the submission once more, under a new event_id (the retried failure's id
 * is part of it), so a recovered address recovers the monitor.
 */
final class DidMyFormSend
{
    /** fmh_ plus the 40 letters and digits DidMyFormSend makes (DidMyFormSend: Str::random(40)). */
    public const KEY_PATTERN = '/^fmh_[A-Za-z0-9]{40}$/';

    public const KEY_MESSAGE = 'The DidMyFormSend key must be fmh_ followed by 40 letters and digits. Copy it from your monitor\'s page on didmyformsend.com.';

    /** The receiving side's rules (DidMyFormSend's recipient and endpoint validation). */
    public const MASK_PATTERN = '/^[a-z0-9._%+-]\*\*\*@[a-z0-9-]+(\.[a-z0-9-]+)+$/';

    public const MASK_MAX = 258;

    public const MAX_RECIPIENTS = 5;

    public const MAX_BODY_BYTES = 2048;

    /** Worst first: an address that refuses mail is what the owner must fix; "error" is the provider's side. */
    public const KIND_ORDER = ['bounced', 'suppressed', 'error'];

    public static function url(): string
    {
        return rtrim((string) config('services.didmyformsend.url'), '/').'/api/v1/heartbeat';
    }

    /** Whether the form holds a key, read from the stored value so nothing is decrypted to answer it. */
    public static function enabled(Form $form): bool
    {
        return filled($form->getAttributes()['monitor_key'] ?? null);
    }

    /** The first 8 characters ("fmh_abcd"), the most of a key ever shown or logged. */
    public static function prefix(?string $key): ?string
    {
        return $key === null || $key === '' ? null : substr($key, 0, 8);
    }

    /** The mailer's name as the receiving side takes it: lower case, a to z, 0 to 9 and _, at most 32. */
    public static function provider(?string $mailer): string
    {
        $name = trim((string) preg_replace('/[^a-z0-9_]+/', '_', strtolower((string) ($mailer ?: config('mail.default')))), '_');

        return substr($name, 0, 32) ?: 'mail';
    }

    /**
     * Called after an email about a submission reached its final outcome. Never throws: a report
     * problem must not touch delivery (a throw from send() would make the queue send the email again).
     *
     * @param  bool  $sent  true when the transport accepted this email
     * @param  ?int  $round  the DeliveryFailure id this email is a "Send again" of, null for a first send
     */
    public static function afterMail(Submission $submission, bool $sent, ?int $round, ?string $mailer): void
    {
        try {
            $form = $submission->form;

            if (! $form || ! self::enabled($form)) {
                return;
            }

            $pending = (int) $submission->newQuery()->whereKey($submission->id)->value('emails_pending');
            $kinds = DeliveryFailure::where('submission_id', $submission->id)->pluck('kind');

            // Another email of this submission has no final outcome yet: the one that ends last reports.
            if ($pending > $kinds->count()) {
                return;
            }

            $failed = $kinds->reject(fn (string $kind) => in_array($kind, [DeliveryFailure::HELD_FORM, DeliveryFailure::HELD_ALL], true));

            if ($failed->isNotEmpty()) {
                $result = 'failed';
                $kind = collect(self::KIND_ORDER)->first(fn (string $k) => $failed->contains($k)) ?? 'error';
            } elseif ($sent) {
                $result = 'sent';
                $kind = null;
            } else {
                // Only held emails, or a recipient removed while its email waited: nothing went out.
                return;
            }

            SendMonitorHeartbeat::dispatch(
                $form->id,
                $result,
                $kind,
                self::provider($mailer),
                now()->getTimestamp(),
                self::eventId($submission->id, $result, $round),
            );
        } catch (\Throwable $e) {
            // Never the key: the job carries none, and this names only the class of the problem.
            \Illuminate\Support\Facades\Log::warning('DidMyFormSend report not queued', ['submission_id' => $submission->id, 'error' => class_basename($e)]);
        }
    }

    /** 64 hex characters: unique per submission, result and Send again round, and the same if two workers race. */
    public static function eventId(int $submissionId, string $result, ?int $round): string
    {
        return hash_hmac('sha256', 'didmyformsend:'.$submissionId.':'.$result.':'.($round ?? 0), (string) config('app.key'));
    }

    /**
     * Every current notification target of the form, in a stable order (the owner first, then the
     * confirmed recipients by when they were added), masked, at most 5. The same set on every
     * heartbeat, so the monitor never sees it flip between two emails of one form. An address the
     * receiving side's mask cannot express (a non-ASCII domain) is left out, the same way every time.
     *
     * @return list<array{masked: string, sha256: string}>
     */
    public static function recipients(Form $form): array
    {
        $out = [];
        foreach ($form->mailTargets() as $target) {
            $email = strtolower(trim((string) ($target instanceof FormRecipient ? $target->email : $form->user->email)));
            $at = strrpos($email, '@');
            if ($at === false || $at === 0) {
                continue;
            }

            $masked = $email[0].'***@'.substr($email, $at + 1);
            $hash = hash('sha256', $email);
            if (strlen($masked) > self::MASK_MAX || ! preg_match(self::MASK_PATTERN, $masked) || isset($out[$hash])) {
                continue;
            }

            $out[$hash] = ['masked' => $masked, 'sha256' => $hash];
        }

        return array_slice(array_values($out), 0, self::MAX_RECIPIENTS);
    }

    /**
     * The JSON body: only these fields, and nothing a visitor wrote, no visitor address, no form
     * name and no site address.
     *
     * @return array<string, mixed>
     */
    public static function body(Form $form, string $result, ?string $kind, string $provider, int $occurredAt, string $eventId): array
    {
        $body = ['result' => $result, 'provider' => $provider];
        if ($kind !== null) {
            $body['provider_status'] = $kind;
        }
        $body += ['occurred_at' => $occurredAt, 'event_id' => $eventId];

        $recipients = self::recipients($form);
        // Five of the longest masks the receiving side takes could pass its 2 KB: drop from the end until it fits.
        while ($recipients !== [] && strlen(json_encode($body + ['recipients' => $recipients])) > self::MAX_BODY_BYTES) {
            array_pop($recipients);
        }
        if ($recipients !== []) {
            $body['recipients'] = $recipients;
        }

        return $body;
    }
}
