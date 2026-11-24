<?php

namespace App\Mail;

use App\Models\DeliveryFailure;
use App\Models\FormRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The weekly count to one of a form's confirmed recipients: how many messages came in last week and
 * the link to their messages page. Short and plain on purpose, with nothing a visitor typed, so it has
 * the best chance of reaching the inbox when the emails about each message did not. Under the owner's
 * sender name and without FormHaven branding, like the recipient's other emails. Built by
 * app/Console/Commands/SendRecipientSummaries.php, which counts and decides who gets one.
 */
class RecipientSummary extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels { __serialize as serializeModels; }

    /** Queued as an id, not a model: an address removed or stopped before the queue gets here is not mailed. */
    public int $recipientId;

    public int $tries = DeliveryFailure::TRIES;

    /** @var list<int> seconds before the second and third attempt */
    public array $backoff = [60, 600];

    /**
     * @param  string  $weekStart  Monday of the counted week (Y-m-d, Europe/Amsterdam)
     * @param  bool  $partial  the form keeps messages for fewer days than the week is old, so older ones are not counted
     */
    public function __construct(public ?FormRecipient $recipient, public int $count, public string $weekStart, public bool $partial = false)
    {
        $this->recipientId = $recipient->id;
    }

    public function __serialize(): array
    {
        $this->recipient = null;

        return $this->serializeModels();
    }

    public function send($mailer)
    {
        $this->recipient = FormRecipient::with('form.user')->find($this->recipientId);

        // Removed, stopped the count, or the owner switched it off since it was queued: nothing to send.
        return $this->recipient && $this->recipient->getsWeeklySummary() ? parent::send($mailer) : null;
    }

    /**
     * Not recorded as a DeliveryFailure on purpose: a failure without a submission reads as a lost
     * confirmation link in the form's health and the owner's alert, and no message is waiting on this
     * email. The next message to this address goes through SubmissionReceived, which records and
     * reports a dead address. The address is left out of the log, as in ReportDeliveryFailure.
     */
    public function failed(\Throwable $e): void
    {
        Log::warning('Weekly count to a recipient not delivered', [
            'recipient' => $this->recipientId,
            'week' => $this->weekStart,
            'error' => preg_replace('/[^\s<>,;:"]+@[^\s<>,;:"]+/', '[address]', $e->getMessage()),
        ]);
    }

    /** "16 to 22 November" or "28 November to 4 December". */
    public function weekLabel(): string
    {
        $start = Carbon::parse($this->weekStart);
        $end = $start->copy()->addDays(6);

        return $start->month === $end->month
            ? $start->format('j').' to '.$end->format('j F')
            : $start->format('j F').' to '.$end->format('j F');
    }

    /** "4 messages", or "at least 4 messages" when the form no longer keeps the whole week. */
    public function countLabel(): string
    {
        return ($this->partial ? 'at least ' : '').$this->count.' '.($this->count === 1 ? 'message' : 'messages');
    }

    public function envelope(): Envelope
    {
        $form = $this->recipient->form;

        return new Envelope(
            from: new Address(config('mail.from.address'), $form->senderName()),
            subject: $form->name.': '.$this->countLabel().' last week',
        );
    }

    /** One-click unsubscribe in the client's mail app, which stops only this weekly count. */
    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->recipient->summaryStopUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.recipient-summary', text: 'mail.recipient-summary-text', with: [
            'form' => $this->recipient->form,
            'weekLabel' => $this->weekLabel(),
            'countLabel' => $this->countLabel(),
            'fromAddress' => config('mail.from.address'),
        ]);
    }
}
