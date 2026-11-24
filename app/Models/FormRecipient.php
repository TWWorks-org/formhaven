<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;

/** An extra address a form's submissions are emailed to, once it has confirmed. */
class FormRecipient extends Model
{
    public const MAX_PER_FORM = 5;

    /** How long the link in a confirmation email works. */
    public const CONFIRM_DAYS = 7;

    protected $fillable = ['email'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'summary_stopped_at' => 'datetime', 'summary_week' => 'date'];
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** Emails our mail provider did not deliver, oldest first. */
    public function deliveryFailures(): HasMany
    {
        return $this->hasMany(DeliveryFailure::class, 'form_recipient_id')->orderBy('id');
    }

    /** The shape the API and MCP return. $form saves a query when the caller already holds it. */
    public function toApi(?Form $form = null): array
    {
        return [
            'email' => $this->email,
            // false until they press the link we emailed them; nothing is sent to them before that.
            'confirmed' => $this->isConfirmed(),
            'added_at' => $this->created_at->toIso8601String(),
            // Why the last email to this address did not arrive; null when nothing is undelivered.
            'delivery_problem' => $this->deliveryFailures->last()?->explain(),
            // The read-only page of the form's messages this address can open; null while it is not available.
            'messages_url' => $this->canViewMessages($form) ? $this->messagesUrl() : null,
            // true while this address gets the weekly count (confirmed, both form settings on, not stopped by them).
            'weekly_summary' => $this->getsWeeklySummary($form),
            // true once the address pressed "Stop the weekly count" itself; only removing and adding it again undoes that.
            'weekly_summary_stopped' => $this->summary_stopped_at !== null,
        ];
    }

    /** The weekly count needs the messages page (its link is the point) and an address that did not stop it. */
    public function getsWeeklySummary(?Form $form = null): bool
    {
        $form ??= $this->form;

        return $this->canViewMessages($form) && $form->recipients_weekly_summary && $this->summary_stopped_at === null;
    }

    /** The messages page opens for a confirmed address on a form whose owner switched it on. */
    public function canViewMessages(?Form $form = null): bool
    {
        return $this->isConfirmed() && (bool) ($form ?? $this->form)->recipients_can_view;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function confirmUrl(): string
    {
        return URL::temporarySignedRoute('recipients.confirm', now()->addDays(self::CONFIRM_DAYS), $this);
    }

    /**
     * The read-only page of the form's messages. Never expires, like the stop link; whether it opens
     * is decided on every visit (canViewMessages), so the owner can close it at any time.
     */
    public function messagesUrl(): string
    {
        return URL::signedRoute('recipients.messages', $this);
    }

    /** Stops only the weekly count; the emails about each message keep coming. Never expires, like stopUrl(). */
    public function summaryStopUrl(): string
    {
        return URL::signedRoute('recipients.summary.stop', $this);
    }

    /** Never expires: it sits in every email this address gets. */
    public function stopUrl(): string
    {
        return URL::signedRoute('recipients.stop', $this);
    }
}
