<?php

namespace App\Mail;

use App\Models\AbuseReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Tells us a report came in. Reply-To is the reporter, so the decision can be answered straight from the inbox. */
class AbuseReportFiled extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public AbuseReport $report) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Abuse report #'.$this->report->id.': decide and reply',
            replyTo: [$this->report->email],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.abuse-report-filed');
    }
}
