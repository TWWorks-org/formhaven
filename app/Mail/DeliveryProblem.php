<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the owner an email to one of their form's addresses did not arrive. Built from plain
 * values, not models, so a failure cleared by Send again before the queue gets here cannot fail
 * this job. It has no failed() on purpose: an alert about a failed alert would be a loop.
 */
class DeliveryProblem extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param bool $confirmation true when the email that failed was the recipient's confirmation link */
    public function __construct(
        public string $formName,
        public string $formUrl,
        public string $email,
        public string $explain,
        public string $fix,
        public int $waiting,
        public bool $confirmation,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->confirmation
            ? 'The confirmation email to '.$this->email.' did not arrive'
            : 'Messages from '.$this->clean($this->formName).' are not reaching '.$this->email);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.delivery-problem', text: 'mail.delivery-problem-text');
    }

    /** The form name is typed by a person and goes into a header. */
    private function clean(string $text): string
    {
        return \Illuminate\Support\Str::limit(trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text)), 80);
    }
}
