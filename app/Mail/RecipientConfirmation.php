<?php

namespace App\Mail;

use App\Actions\ReportDeliveryFailure;
use App\Models\DeliveryFailure;
use App\Models\FormRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Asks an added address whether it wants a form's submissions. Sent under our own
 * name on purpose: it is the one email that tells the recipient who processes them.
 */
class RecipientConfirmation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels { __serialize as serializeModels; }

    /** Queued as an id, not a model: an address removed before the queue gets here is simply not mailed. */
    public int $recipientId;

    public int $tries = DeliveryFailure::TRIES;

    /** @var list<int> seconds before the second and third attempt */
    public array $backoff = [60, 600];

    public function __construct(public ?FormRecipient $recipient)
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
        $this->recipient = FormRecipient::find($this->recipientId);

        return $this->recipient && ! $this->recipient->isConfirmed() ? parent::send($mailer) : null;
    }

    /** The link never arrived: the owner is told, and the recipient's line in the dashboard says so. */
    public function failed(\Throwable $e): void
    {
        $recipient = FormRecipient::find($this->recipientId);

        if (! $recipient) {
            return;
        }

        $failure = DeliveryFailure::record($recipient->form, null, $recipient, $recipient->email, $e);
        app(ReportDeliveryFailure::class)->handle($failure);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm: get messages from '.$this->recipient->form->name);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.recipient-confirmation', text: 'mail.recipient-confirmation-text');
    }
}
