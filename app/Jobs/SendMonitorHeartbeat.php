<?php

namespace App\Jobs;

use App\Models\Form;
use App\Support\DidMyFormSend;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One report to DidMyFormSend about one submission's emails (App\Support\DidMyFormSend). Kept
 * away from delivery: one try, a 2 second connect and 3 second total timeout, every exception
 * caught, so it never lands in failed_jobs and never changes a delivery. It carries no key: the
 * key is read from the form when it runs, so a key removed in the meantime stops it. The request
 * goes only to the configured DidMyFormSend address, and no log line holds more of the key than
 * its first 8 characters.
 */
class SendMonitorHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(
        public int $formId,
        public string $result,
        public ?string $kind,
        public string $provider,
        public int $occurredAt,
        public string $eventId,
    ) {}

    public function handle(): void
    {
        $prefix = null;

        try {
            $form = Form::with('user')->find($this->formId);

            if (! $form || ! DidMyFormSend::enabled($form)) {
                return;
            }

            $key = (string) $form->monitor_key;
            $prefix = DidMyFormSend::prefix($key);

            if (! preg_match(DidMyFormSend::KEY_PATTERN, $key)) {
                return;
            }

            $response = Http::withToken($key)
                ->acceptJson()
                ->connectTimeout(2)
                ->timeout(3)
                ->withOptions(['allow_redirects' => false])
                ->withUserAgent('FormHaven/1 (+'.config('app.url').')')
                ->post(DidMyFormSend::url(), DidMyFormSend::body($form, $this->result, $this->kind, $this->provider, $this->occurredAt, $this->eventId));

            $status = $response->status();

            if (in_array($status, [401, 410], true)) {
                // Only for the key this report used: one pasted in the meantime is not marked.
                if ((string) $form->fresh()->monitor_key === $key) {
                    $form->forceFill(['monitor_refused_at' => now()])->save();
                }
                Log::info('DidMyFormSend refused the key', ['form' => $form->public_id, 'status' => $status, 'key' => $prefix]);
            } elseif ($response->successful()) {
                if ($form->monitor_refused_at !== null) {
                    $form->forceFill(['monitor_refused_at' => null])->save();
                }
            } else {
                Log::info('DidMyFormSend did not take the report', ['form' => $form->public_id, 'status' => $status, 'key' => $prefix]);
            }
        } catch (\Throwable $e) {
            // The class only: a message can carry the URL or headers, and never decides anything here.
            Log::warning('DidMyFormSend report not sent', ['form_id' => $this->formId, 'key' => $prefix, 'error' => class_basename($e)]);
        }
    }
}
