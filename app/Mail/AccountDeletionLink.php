<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * Confirms deleting an account that has no password of its own (made through Google).
 * Sent straight away, not queued: the person is waiting for it on the settings page.
 */
class AccountDeletionLink extends Mailable
{
    use Queueable, SerializesModels;

    public const MINUTES = 30;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm deleting your FormHaven account');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.account-deletion-link', with: [
            'url' => URL::temporarySignedRoute('account.delete', now()->addMinutes(self::MINUTES), ['user' => $this->user->id]),
            'minutes' => self::MINUTES,
        ]);
    }
}
