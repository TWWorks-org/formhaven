<?php

namespace App\Http\Controllers;

use App\Actions\ReceiveSubmission;
use App\Exceptions\SubmissionRefused;
use App\Models\Form;
use App\Models\FormClaim;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class SubmitController extends Controller
{
    public function __invoke(Request $request, string $publicId, ReceiveSubmission $receive)
    {
        $form = Form::where('public_id', $publicId)->first();

        if (! $form) {
            // Reserved by an agent, not switched on yet: nothing is stored until the owner activates it.
            if (FormClaim::waiting()->where('public_id', $publicId)->exists()) {
                return $this->fail($request, new SubmissionRefused(403, 'This form is not active yet. The site owner still has to open the activation link from FormHaven.', 'FORM_NOT_ACTIVE'));
            }

            return $this->fail($request, new SubmissionRefused(404, 'No form exists at this address. Check the form ID in your HTML action.', 'FORM_NOT_FOUND'));
        }

        $referer = $request->headers->get('referer');

        try {
            $receive->handle($form, $request->all(), [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'referer' => $referer,
                'origin' => $request->headers->get('origin'),
            ]);
        } catch (SubmissionRefused $e) {
            return $this->fail($request, $e, $form);
        }

        // The owner's saved thank-you page wins over the form's _next field (Formspark calls it _redirect).
        $requested = filled($request->input('_next')) ? $request->input('_next') : $request->input('_redirect');
        $next = $form->redirect_url ?: $form->acceptedNext($requested, $referer);

        // Spam gets the same answer as a real submission, so bots learn nothing.
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => 'Thanks, your message was received.', 'next' => $next ?: route('submit.thanks')]);
        }

        if ($next) {
            return redirect()->away($next);
        }

        // The thank-you page looks up the form and links back to the site its owner
        // saved, never to an address from the request: the Referer can be forged.
        // Signed and short-lived, so nobody can share a lasting page on our own domain
        // saying "Back to <a site they saved>".
        return redirect()->to($form->siteOrigin()
            ? URL::temporarySignedRoute('submit.thanks', now()->addMinutes(10), ['form' => $form->public_id])
            : route('submit.thanks'));
    }

    /** JSON in our shape plus Formspree's ("error" and "errors"), so code written for either keeps working. */
    private function fail(Request $request, SubmissionRefused $e, ?Form $form = null)
    {
        $message = $e->getMessage();

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'message' => $message,
                'error' => $message,
                'errors' => [array_filter(['code' => $e->errorCode, 'field' => $e->field, 'message' => $message])],
            ], $e->status);
        }

        // Formspark's _error: the site's own error page, held to the same rules as _next.
        if ($form && ($error = $form->acceptedNext($request->input('_error'), $request->headers->get('referer')))) {
            return redirect()->away($error);
        }

        // Only a visitor on our error page has a Back button to press; a site's own JavaScript shows the bare message.
        if ($e->errorCode === 'TYPE_EMAIL') {
            $message .= ' Use your browser\'s Back button to fix it: your message is still there.';
        }

        return response()->view('submit-error', ['message' => $message, 'back' => $form?->siteOrigin()], $e->status);
    }
}
