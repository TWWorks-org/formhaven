<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateForm;
use App\Actions\DeleteForm;
use App\Actions\FormEmails;
use App\Actions\FormSettings;
use App\Actions\FormSpamSettings;
use App\Actions\RetryDeliveries;
use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormRecipient;
use App\Support\PrivacyText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class FormController extends Controller
{
    public function index(Request $request)
    {
        $forms = $request->user()->formsForKey()
            ->with(['recipients', 'deliveryFailures'])
            ->withCount(['submissions' => fn ($q) => $q->where('spam', false)])
            ->latest()->get();

        return response()->json(['data' => $forms->map->toApi()]);
    }

    public function store(Request $request, CreateForm $create)
    {
        if ($request->user()->keyFormScope() !== null) {
            return response()->json(['message' => CreateForm::SCOPED_KEY_REFUSAL], 403);
        }

        $form = $create->handle($request->user(), $request->only(array_keys(CreateForm::RULES)));

        return response()->json([
            'data' => $form->toApi(),
            'next' => 'Set your HTML form\'s action to the endpoint, method POST. Every input needs a name.',
        ], 201);
    }

    public function show(Request $request, string $id)
    {
        return response()->json(['data' => $this->find($request, $id)->toApi()]);
    }

    /** Every field the dashboard's settings, emails and spam tabs change, in one call. */
    public function update(Request $request, FormSettings $settings, FormEmails $emails, FormSpamSettings $spam, string $id)
    {
        $form = $this->find($request, $id);
        $other = array_diff(array_keys($request->all()), self::patchable());

        if ($other !== []) {
            return response()->json([
                'message' => 'PATCH does not change '.implode(', ', $other).'. It changes: '.implode(', ', self::patchable()).'.',
            ], 422);
        }

        // Everything is checked before anything is saved, so one bad field does not leave half a change behind.
        Validator::make($request->all(), FormEmails::SETTINGS_RULES + FormSpamSettings::RULES, FormEmails::SETTINGS_MESSAGES)->validate();
        FormSettings::check($request->only(array_keys(FormSettings::RULES)));
        if ($request->has('spam_words')) {
            FormSpamSettings::words($request->input('spam_words'));
        }

        $settings->update($form, $request->only(array_keys(FormSettings::RULES)));
        $emails->update($form, $request->only(array_keys(FormEmails::SETTINGS_RULES)));
        $spam->update($form, $request->only(array_keys(FormSpamSettings::RULES)));

        $webhookSet = filled($request->input('webhook_url'));

        return response()->json(array_filter([
            'data' => $form->toApi(),
            'webhook_signing_secret' => $webhookSet ? $form->webhook_secret : null,
            'next' => implode(' ', array_filter([
                $request->boolean('bot_check') ? 'Add '.Form::botCheckScript().' once to every page with this form; without it every post goes to spam.' : null,
                $webhookSet ? 'Verify the FormHaven-Signature header of each webhook with webhook_signing_secret: v1 is the hex HMAC-SHA256 of "<t>.<raw body>".' : null,
            ])) ?: null,
        ]));
    }

    /** @return list<string> */
    public static function patchable(): array
    {
        return [...array_keys(FormSettings::RULES), ...array_keys(FormEmails::SETTINGS_RULES), ...array_keys(FormSpamSettings::RULES)];
    }

    /** Emails the address a confirmation link; nothing else goes to it until it is pressed. */
    public function addRecipient(Request $request, FormEmails $emails, string $id)
    {
        $form = $this->find($request, $id);
        $recipient = $emails->add($form, (string) $request->input('email'));

        return response()->json([
            'data' => $recipient->toApi(),
            'next' => "We emailed {$recipient->email} a confirmation link. It gets this form's submissions once it is pressed; GET the form to see \"confirmed\".",
        ], 201);
    }

    public function resendConfirmation(Request $request, FormEmails $emails, string $id, string $email)
    {
        $recipient = $this->recipient($this->find($request, $id), $email);
        $emails->resend($recipient);

        return response()->json(['data' => $recipient->toApi(), 'next' => "Sent the confirmation link to {$recipient->email} again."]);
    }

    public function removeRecipient(Request $request, string $id, string $email)
    {
        $recipient = $this->recipient($this->find($request, $id), $email);
        $recipient->delete();

        return response()->json(['data' => null, 'next' => "{$recipient->email} gets no more submissions from this form."]);
    }

    /** Deletes the form with its submissions. The body must repeat the form's name as "confirm". Full keys only. */
    public function destroy(Request $request, DeleteForm $delete, string $id)
    {
        if ($request->user()->keyFormScope() !== null) {
            return response()->json(['message' => 'This key belongs to one form and cannot delete it. Use an API key from the dashboard.'], 403);
        }

        $form = $this->find($request, $id);
        $name = $form->name;
        $submissions = $delete->handle($form, $request->input('confirm'));

        return response()->json(['data' => null, 'next' => DeleteForm::summary($name, $submissions, $request->user()->isPaying())]);
    }

    public function submissions(Request $request, string $id)
    {
        $page = $this->find($request, $id)->submissions()
            ->with('deliveryFailures')
            ->where('spam', $request->boolean('spam'))
            ->latest()
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return response()->json([
            'data' => collect($page->items())->map->toApi(),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ]);
    }

    /** Queues the form's undelivered messages once more; the form's health block lists them. */
    public function retryDeliveries(Request $request, RetryDeliveries $retry, string $id)
    {
        $result = $retry->handle($this->find($request, $id));

        return response()->json(['data' => $result, 'next' => RetryDeliveries::summary($result).($result['queued'] ? ' GET the form a few minutes later: health shows whatever still did not arrive.' : '')]);
    }

    /** The paragraph the site's privacy policy needs for this form, from its live settings. */
    public function privacyText(Request $request, string $id)
    {
        $form = $this->find($request, $id);
        $language = (string) $request->query('lang', 'en');

        if (! in_array($language, PrivacyText::LANGUAGES, true)) {
            return response()->json(['message' => 'lang must be one of: '.implode(', ', PrivacyText::LANGUAGES).'.'], 422);
        }

        return response()->json(['data' => PrivacyText::for($form, $language)]);
    }

    private function recipient(Form $form, string $email): FormRecipient
    {
        return $form->recipients()->where('email', strtolower($email))->firstOr(fn () => abort(response()->json([
            'message' => 'This form does not email that address. GET /api/v1/forms/{id} lists its recipients.',
        ], 404)));
    }

    private function find(Request $request, string $id): Form
    {
        return $request->user()->formsForKey()->where('public_id', $id)->firstOr(fn () => abort(response()->json([
            'message' => 'No form with this id on your account. GET /api/v1/forms lists your forms.',
        ], 404)));
    }
}
