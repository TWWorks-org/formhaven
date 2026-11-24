<?php

namespace App\Mail;

use App\Models\AbuseReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The receipt a reporter gets for a notice (DSA art. 16(4)). */
class AbuseReportReceived extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public AbuseReport $report) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'We received your report #'.$this->report->id);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.abuse-report-received');
    }
}
