<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a form's owner that the form reached its daily email limit (App\Support\MailBudget), at
 * most once a UTC day per form. Plain values, and no failed(): a notice about mail must not loop.
 */
class MailLimitReached extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $formName, public string $formUrl, public int $limit) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: \Illuminate\Support\Str::limit(trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', $this->formName)), 80).' reached its daily email limit');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.mail-limit-reached', text: 'mail.mail-limit-reached-text');
    }
}
