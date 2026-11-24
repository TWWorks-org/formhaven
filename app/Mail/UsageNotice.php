<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Tells the owner how much of this month's submissions they used, before emails stop and when they do. */
class UsageNotice extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param string $level a percentage from config('billing.usage_notices'), or "stopped" */
    public function __construct(public User $user, public string $level, public int $used) {}

    public function envelope(): Envelope
    {
        $paid = $this->user->planKey() === 'paid';

        return new Envelope(subject: match (true) {
            $this->level === 'stopped' => 'Form emails paused: new messages are only in your dashboard',
            $this->level === '100' && $paid => 'You used this month\'s included FormHaven submissions',
            $this->level === '100' => 'You reached this month\'s FormHaven limit',
            default => 'You used '.$this->level.'% of this month\'s FormHaven submissions',
        });
    }

    public function content(): Content
    {
        $plan = $this->user->plan();

        return new Content(markdown: 'mail.usage-notice', with: [
            'paid' => ($plan['key'] ?? null) === 'paid',
            'limit' => $plan['submissions'] ?? 0,
            'cutoff' => $this->user->emailCutoff() ?? 0,
            'extraPrice' => \App\Support\Bill::format(config('billing.per_form.extra_eur'), ''),
        ]);
    }
}
