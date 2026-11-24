<?php

namespace App\Actions;

use App\Mail\RecipientConfirmation;
use App\Models\Form;
use App\Models\FormRecipient;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Who a form emails, in one place, so the dashboard, the API and MCP enforce the
 * same rules: confirmed addresses only, a cap per form, a daily cap on
 * confirmation emails, which go to addresses we have never mailed, whether
 * those addresses may open a read-only page of the form's messages, and whether
 * they get a weekly count of them.
 */
class FormEmails
{
    public const DAILY_CONFIRMATIONS = 20;

    public const SETTINGS_RULES = [
        'notify_owner' => ['sometimes', 'boolean'],
        // It goes into the From header, so nothing that could break out of the display name.
        'sender_name' => ['sometimes', 'nullable', 'string', 'max:60', 'not_regex:/[<>"\\\\\r\n]/'],
        // On: each confirmed recipient's emails link to a read-only page of the form's messages.
        'recipients_can_view' => ['sometimes', 'boolean'],
        // On (default): each confirmed recipient gets a Monday count of last week's messages with the
        // link to that page. Sent only while recipients_can_view is on (app/Console/Commands/SendRecipientSummaries.php).
        'recipients_weekly_summary' => ['sometimes', 'boolean'],
    ];

    public const SETTINGS_MESSAGES = ['sender_name.not_regex' => 'Leave out < > " and backslashes.'];

    /** @throws ValidationException under the key "email" */
    public function add(Form $form, string $email): FormRecipient
    {
        $email = strtolower(trim($email));

        Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:254', function ($attribute, $value, $fail) use ($form) {
            if ($value === strtolower($form->user->email)) {
                $fail('That is your own address. Use "Email me too" (notify_owner) instead.');
            } elseif ($form->recipients()->where('email', $value)->exists()) {
                $fail('That address is already on this form.');
            } elseif ($form->recipients()->count() >= FormRecipient::MAX_PER_FORM) {
                $fail('A form can email up to '.FormRecipient::MAX_PER_FORM.' addresses. Remove one first.');
            }
        }]], [], ['email' => 'email address'])->validate();

        $this->spendConfirmation($form);

        $recipient = $form->recipients()->create(['email' => $email]);
        Mail::to($recipient->email)->queue(new RecipientConfirmation($recipient));

        return $recipient;
    }

    /** @throws ValidationException under the key "email" */
    public function resend(FormRecipient $recipient): void
    {
        if ($recipient->isConfirmed()) {
            throw ValidationException::withMessages(['email' => $recipient->email.' has already confirmed.']);
        }

        $this->spendConfirmation($recipient->form);
        // The old link's failure is answered by this send; a new failure is recorded if it fails too.
        $recipient->deliveryFailures()->whereNull('submission_id')->delete();
        Mail::to($recipient->email)->queue(new RecipientConfirmation($recipient));
    }

    /** @throws ValidationException */
    public function update(Form $form, array $input): Form
    {
        $data = Validator::make($input, self::SETTINGS_RULES, self::SETTINGS_MESSAGES)->validate();

        if (array_key_exists('sender_name', $data)) {
            $data['sender_name'] = trim((string) $data['sender_name']) ?: null;
        }

        $form->update($data);

        return $form;
    }

    private function spendConfirmation(Form $form): void
    {
        // A confirmation email goes to an address we never mailed, in the owner's name, so only an
        // owner whose own address is proven can send one: by any path (dashboard, REST, MCP).
        if (! $form->user->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['email' => 'Confirm your own email address first: press the link we sent to '.$form->user->email.', or send it again from Settings. Then add this address.']);
        }

        $key = 'recipient-confirmations:'.$form->user_id;

        if (RateLimiter::tooManyAttempts($key, self::DAILY_CONFIRMATIONS)) {
            throw ValidationException::withMessages(['email' => 'You sent '.self::DAILY_CONFIRMATIONS.' confirmation emails today. Try again in '.ceil(RateLimiter::availableIn($key) / 3600).' hours.']);
        }

        RateLimiter::hit($key, 86400);
    }
}
