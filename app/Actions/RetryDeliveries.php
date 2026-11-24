<?php

namespace App\Actions;

use App\Mail\SubmissionReceived;
use App\Models\DeliveryFailure;
use App\Models\Form;
use App\Models\Submission;
use App\Support\MailBudget;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * "Send again": queues every undelivered message of a form once more, for the dashboard, REST and
 * MCP alike. A send that fails again records a new failure through SubmissionReceived::failed(), so
 * nothing has to be restored here. Confirmation links are sent again with FormEmails::resend().
 */
class RetryDeliveries
{
    public const MAX_PER_RUN = 50;

    public const RUNS_PER_HOUR = 3;

    /**
     * @return array{queued: int, dropped: int, left: int}
     *
     * @throws ValidationException under the key "retry"
     */
    public function handle(Form $form): array
    {
        $key = 'retry-deliveries:'.$form->id;

        // Every run sends real email from a shared daily quota.
        if (RateLimiter::tooManyAttempts($key, self::RUNS_PER_HOUR)) {
            throw ValidationException::withMessages(['retry' => 'You pressed Send again '.self::RUNS_PER_HOUR.' times in the last hour. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes.']);
        }
        RateLimiter::hit($key, 3600);

        $queued = $dropped = 0;
        $held = null;

        $failures = $form->deliveryFailures()->whereNotNull('submission_id')
            ->with(['submission', 'recipient'])->limit(self::MAX_PER_RUN)->get();

        foreach ($failures as $failure) {
            $submission = $failure->submission;
            $recipient = $failure->recipient;

            // Answers gone, or the address stopped confirming: nothing left to deliver to anyone.
            if (! $submission || $submission->isForgotten() || ($failure->form_recipient_id && ! $recipient?->isConfirmed())) {
                $failure->delete();
                $submission && $this->doneWith($submission);
                $dropped++;

                continue;
            }

            // Sending again takes from the same daily limits as a first send. Past one, the rest stay
            // where they are for the next Send again.
            if ($held = MailBudget::takeForForm($form)) {
                break;
            }

            $failure->delete();

            try {
                Mail::to($recipient?->email ?? $form->user->email)->queue(new SubmissionReceived($submission->setRelation('form', $form), $recipient, $failure->id));
            } catch (TransportExceptionInterface) {
                // Only on a synchronous queue: the failure is already recorded again by failed().
            }
            $queued++;
        }

        return ['queued' => $queued, 'dropped' => $dropped, 'left' => $form->deliveryFailures()->whereNotNull('submission_id')->count(), 'limit_reached' => $held];
    }

    /** A message that will not be sent to this address: count it as done, as SubmissionReceived::send() does. */
    private function doneWith(Submission $submission): void
    {
        $submission->newQuery()->whereKey($submission->id)->where('emails_pending', '>', 0)->decrement('emails_pending');

        if (! $submission->form->store_submissions && (int) $submission->fresh()?->emails_pending === 0) {
            $submission->forget();
        }
    }

    /** The answer REST, MCP and the dashboard give, in words. */
    public static function summary(array $result): string
    {
        $limit = match ($result['limit_reached'] ?? null) {
            DeliveryFailure::HELD_FORM => 'This form reached its limit of '.MailBudget::perForm().' emails a day, so '.$result['left'].' '.($result['left'] === 1 ? 'message waits' : 'messages wait').'. Press Send again after 00:00 UTC, when the limit starts over.',
            DeliveryFailure::HELD_ALL => 'FormHaven reached its daily email limit for all forms, so '.$result['left'].' '.($result['left'] === 1 ? 'message waits' : 'messages wait').'. Press Send again after 00:00 UTC, when the limit starts over.',
            default => null,
        };

        return match (true) {
            $result['queued'] === 0 && $result['dropped'] === 0 && $limit === null => 'Nothing is waiting to be sent again.',
            default => trim(($result['queued'] ? 'Sent '.$result['queued'].' '.($result['queued'] === 1 ? 'message' : 'messages').' again. ' : '')
                .($result['dropped'] ? $result['dropped'].' could not be sent: the address was removed or the answers are no longer kept. ' : '')
                .($limit ?? ($result['left'] ? $result['left'].' more are waiting: press Send again once these have gone.' : ''))),
        };
    }
}
