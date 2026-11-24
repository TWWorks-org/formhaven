<?php

namespace App\Mail;

use App\Actions\ReportDeliveryFailure;
use App\Models\DeliveryFailure;
use App\Models\FormRecipient;
use App\Models\Submission;
use App\Support\DidMyFormSend;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * One submission, to the owner (no $recipient) or to one of the form's recipients.
 * A recipient's copy carries the owner's sender name and no FormHaven branding,
 * because it is usually the agency's client reading it.
 */
class SubmissionReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels { __serialize as serializeModels; }

    /** Queued as an id, not a model: a recipient who stops before the queue gets here must not fail the job. */
    public ?int $recipientId;

    /** Set here, not on the worker, so the attempts do not depend on the server's queue unit. */
    public int $tries = DeliveryFailure::TRIES;

    /** @var list<int> seconds before the second and third attempt */
    public array $backoff = [60, 600];

    /**
     * @param  ?int  $retryOf  the DeliveryFailure this email is a "Send again" of: a new round for the
     *                         DidMyFormSend report, so a recovered address gets a heartbeat of its own
     */
    public function __construct(public Submission $submission, public ?FormRecipient $recipient = null, public ?int $retryOf = null)
    {
        $this->recipientId = $recipient?->id;
    }

    public function __serialize(): array
    {
        $this->recipient = null;

        return $this->serializeModels();
    }

    public function envelope(): Envelope
    {
        $replyTo = $this->submission->replyToAddress();

        $form = $this->submission->form;

        return new Envelope(
            from: new Address(config('mail.from.address'), $this->recipient ? $form->senderName() : $this->ownerSenderName()),
            subject: $this->recipient ? $this->submission->mailSubject() : $this->ownerSubject(),
            replyTo: $replyTo ? [new Address($replyTo)] : [],
        );
    }

    /**
     * The owner's copy names the form after the site's own subject ("Correction | Example Bakery corrections"),
     * so one inbox with many sites stays sortable. The site's subject stays first, so filters on it still match.
     */
    private function ownerSubject(): string
    {
        $subject = $this->submission->mailSubject();
        $name = $this->submission->form->name;

        return str_contains(mb_strtolower($subject), mb_strtolower($name)) ? $subject : $subject.' | '.$name;
    }

    /** "Example Bakery corrections via FormHaven": the form name is typed by the owner, so it is cleaned like senderName(). */
    private function ownerSenderName(): string
    {
        $name = trim(preg_replace(['/[<>"\\\\]/', '/[\x00-\x1f\x7f]+/', '/\s+/'], ['', ' ', ' '], $this->submission->form->name));

        return $name === '' ? config('app.name') : \Illuminate\Support\Str::limit($name, 50, '').' via '.config('app.name');
    }

    /**
     * Wipes the answers once the last email for them has left, for a form set not to store them.
     * A failed send keeps them for the retry.
     */
    public function send($mailer)
    {
        $this->recipient = $this->recipientId ? FormRecipient::find($this->recipientId) : null;

        // A recipient who stopped in the meantime gets nothing, but still counts as done,
        // so a form that stores nothing is not left holding the answers.
        $sent = $this->recipientId && ! $this->recipient ? null : parent::send($mailer);

        $submission = $this->submission;
        $submission->newQuery()->whereKey($submission->id)->where('emails_pending', '>', 0)->decrement('emails_pending');

        if (! $submission->form->store_submissions && (int) $submission->fresh()?->emails_pending === 0) {
            $submission->forget();
        }

        // Only for a form with a DidMyFormSend key; reports once the submission's last email is settled. Never throws.
        DidMyFormSend::afterMail($submission, $sent !== null, $this->retryOf, $this->mailer);

        return $sent;
    }

    /**
     * The last attempt failed: record it so the dashboard, the API and the owner see it. The pending
     * count is left alone on purpose, so a form that keeps nothing holds the answers until its
     * retention days pass and Send again can still deliver them.
     */
    public function failed(\Throwable $e): void
    {
        $submission = $this->submission;
        $recipient = $this->recipientId ? FormRecipient::find($this->recipientId) : null;

        // The recipient was removed while the job waited: nobody is missing a message.
        if ($this->recipientId && ! $recipient) {
            return;
        }

        $form = $submission->form;
        $failure = DeliveryFailure::record($form, $submission, $recipient, $recipient?->email ?? $form->user->email, $e);
        app(ReportDeliveryFailure::class)->handle($failure);

        DidMyFormSend::afterMail($submission, false, $this->retryOf, $this->mailer);
    }

    /** Lets the client's mail app offer its own unsubscribe button, which posts to the stop link. */
    public function headers(): Headers
    {
        return new Headers(text: $this->recipient ? [
            'List-Unsubscribe' => '<'.$this->recipient->stopUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ] : []);
    }

    public function content(): Content
    {
        return $this->recipient
            ? new Content(view: 'mail.submission-for-recipient', text: 'mail.submission-for-recipient-text')
            : new Content(markdown: 'mail.submission-received', text: 'mail.submission-received-text');
    }
}
