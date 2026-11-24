<?php

namespace App\Actions;

use App\Mail\DeliveryProblem;
use App\Models\DeliveryFailure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the account owner that an email did not arrive, at most once a day per form and address,
 * so one closed mailbox on a busy form cannot flood them or eat the shared daily mail quota.
 * The owner's own address failing gets no email (we have no other way to reach them, and mailing
 * a dead address again hurts the sending reputation): the dashboard and the API show it instead.
 */
class ReportDeliveryFailure
{
    public function handle(DeliveryFailure $failure): void
    {
        $form = $failure->form;

        // Never the visitor's answers: only which form, what kind and what the provider said.
        Log::warning('Email not delivered', [
            'form' => $form->public_id,
            'kind' => $failure->kind,
            'owner_address' => $failure->isOwners(),
            // The provider names the address in its error ("permanently bounced: a@b.example"); logs need the reason, not the person.
            'error' => preg_replace('/[^\s<>,;:"]+@[^\s<>,;:"]+/', '[address]', $failure->error),
        ]);

        $owner = $form->user;

        if ($failure->isOwners() || ! $owner->hasVerifiedEmail()) {
            return;
        }

        if (! Cache::add('delivery-alert:'.$failure->form_id.':'.sha1($failure->email), 1, now()->addDay())) {
            return;
        }

        Mail::to($owner->email)->queue(new DeliveryProblem(
            formName: $form->name,
            formUrl: route('forms.show', $form),
            email: $failure->email,
            explain: $failure->explain(),
            fix: $failure->fix(),
            waiting: $failure->submission_id === null ? 0 : $form->deliveryFailures()->where('email', $failure->email)->whereNotNull('submission_id')->count(),
            confirmation: $failure->submission_id === null,
        ));
    }
}
