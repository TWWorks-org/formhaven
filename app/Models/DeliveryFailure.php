<?php

namespace App\Models;

use App\Mail\DeliveryRefused;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An email that did not go out: one our mail provider did not deliver (a submission to the owner
 * or a recipient, or a recipient's confirmation link, which has no submission), or a submission
 * email we held back because a daily mail limit was reached (App\Support\MailBudget). Deleted
 * when it is sent again.
 */
class DeliveryFailure extends Model
{
    /** Held: this form reached its own daily email limit (mail.budget.per_form_per_day). */
    public const HELD_FORM = 'held_form';

    /** Held: the daily email limit across all forms was reached (mail.budget.notifications_per_day). */
    public const HELD_ALL = 'held_all';

    public const KINDS = [DeliveryRefused::BOUNCED, DeliveryRefused::SUPPRESSED, 'error', self::HELD_FORM, self::HELD_ALL];

    /** Sent attempts before a job counts as failed; the mailables' $tries. */
    public const TRIES = 3;

    protected $fillable = ['email', 'kind', 'error'];

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(FormRecipient::class, 'form_recipient_id');
    }

    public static function record(Form $form, ?Submission $submission, ?FormRecipient $recipient, string $email, \Throwable $e): self
    {
        $failure = new self([
            'email' => Str::limit(strtolower($email), 254, ''),
            'kind' => $e instanceof DeliveryRefused ? $e->kind : 'error',
            'error' => Str::limit($e->getMessage(), 480),
        ]);
        $failure->form()->associate($form);
        $failure->submission()->associate($submission);
        $failure->recipient()->associate($recipient);
        $failure->save();

        return $failure;
    }

    /** A submission email that was not sent because a daily limit was reached; $kind is HELD_FORM or HELD_ALL. */
    public static function hold(Form $form, Submission $submission, ?FormRecipient $recipient, string $email, string $kind): self
    {
        $failure = new self([
            'email' => Str::limit(strtolower($email), 254, ''),
            'kind' => $kind,
            'error' => $kind === self::HELD_FORM ? 'Daily email limit for this form reached.' : 'Daily email limit for all forms reached.',
        ]);
        $failure->form()->associate($form);
        $failure->submission()->associate($submission);
        $failure->recipient()->associate($recipient);
        $failure->save();

        return $failure;
    }

    public function isHeld(): bool
    {
        return in_array($this->kind, [self::HELD_FORM, self::HELD_ALL], true);
    }

    /** Whose address it is: null for the account owner's. */
    public function isOwners(): bool
    {
        return $this->form_recipient_id === null;
    }

    /** Why it did not arrive, as the owner reads it. Only what the provider told us, nothing beyond it. */
    public function explain(): string
    {
        return match ($this->kind) {
            DeliveryRefused::BOUNCED => 'The mail server refused the address: it does not exist or cannot receive mail.',
            DeliveryRefused::SUPPRESSED => 'This address bounced or marked our mail as spam before, so our mail provider no longer sends to it.',
            self::HELD_FORM => 'This form reached its limit of '.config('mail.budget.per_form_per_day').' emails a day, so we held the email instead of sending it.',
            self::HELD_ALL => 'FormHaven reached its daily email limit for all forms, so we held the email instead of sending it.',
            default => 'Our mail provider could not send the email, after '.self::TRIES.' tries.',
        };
    }

    /** What the owner can do about it. */
    public function fix(): string
    {
        return match ($this->kind) {
            'error' => 'Press Send again. The message itself is saved in your dashboard.',
            self::HELD_FORM => 'Press Send again after 00:00 UTC, when the limit starts over. The message itself is saved in your dashboard. If the messages are spam, turn on the bot check or list your site under Allowed sites.',
            self::HELD_ALL => 'Press Send again after 00:00 UTC, when the limit starts over. The message itself is saved in your dashboard.',
            default => 'Check the address or ask its owner whether the mailbox still works. Then press Send again. If it keeps failing, write to '.config('legal.support_email').' and we will look at it.',
        };
    }
}
