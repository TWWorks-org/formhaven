<?php

namespace App\Jobs;

use App\Models\Form;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One submission to the form's webhook, retried with backoff. The body is built when the
 * submission arrives, so a form that keeps no submissions still delivers its answers
 * after they are wiped. It never ends in failed_jobs, which would keep the answers there
 * indefinitely: the last attempt records the failure on the form and stops.
 */
class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> seconds between attempts: about 37 minutes in total */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(public int $formId, public string $body) {}

    public function handle(): void
    {
        $form = Form::find($this->formId);

        if (! $form?->webhook_url || $form->deliverWebhook($this->body)) {
            return;
        }

        if ($this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 1800);
        }
    }
}
