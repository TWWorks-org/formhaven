<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Tells the operator (OWNER_EMAILS) that today's email budget for all forms is used up, at most once a UTC day. */
class MailBudgetSpent extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public int $limit, public ?string $topForm, public int $topCount, public int $perForm) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'FormHaven used its '.$this->limit.' submission emails for today');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.mail-budget-spent');
    }
}
