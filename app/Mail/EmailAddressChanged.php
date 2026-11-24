<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent to the old address when an account's email changes, so a takeover is never silent. */
class EmailAddressChanged extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $oldEmail) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your FormHaven email address was changed');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.email-address-changed');
    }
}
