<?php

namespace App\Http\Controllers;

use App\Livewire\Actions\Logout;
use App\Mail\AccountDeletionLink;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * The emailed link that deletes an account without a password of its own. It needs both the
 * inbox and a session logged in as that same account, so a stolen session alone cannot delete
 * it. Opening the link only shows a button: mail scanners open every link in an email.
 */
class AccountDeletionController extends Controller
{
    public function __invoke(Request $request, int $user, Logout $logout)
    {
        if (! URL::hasCorrectSignature($request)) {
            return $this->notice('This link is not complete', 'Part of the link is missing or changed. Open it again from the email, or ask for a new one in Settings.', 403);
        }

        if (! URL::signatureHasNotExpired($request)) {
            return $this->notice('This link has expired', 'Deletion links work for '.AccountDeletionLink::MINUTES.' minutes. Ask for a new one in Settings.', 403);
        }

        $account = $request->user();

        if ($account->id !== $user) {
            return $this->notice('Log in to the account first', 'This link deletes a different account from the one you are logged in to. Log in to the account the email went to, then open the link again.', 403);
        }

        if ($request->isMethod('get')) {
            return response()->view('recipient', ['state' => 'notice', 'title' => 'Delete your account?', 'text' => 'Your forms and every submission are deleted straight away, and any paid plan is cancelled. This cannot be undone.', 'action' => 'Delete my account']);
        }

        // Same order as the settings page: Stripe first, then log out, then delete (logging
        // out saves the user, which would write a deleted account back).
        try {
            $account->cancelSubscriptionsNow();
        } catch (\Throwable $e) {
            report($e);

            return $this->notice('Nothing was deleted', 'Your subscription could not be cancelled, so your account is untouched. Try again in a minute, or email '.config('legal.email').'.', 503);
        }

        $logout();
        $account->delete();

        return $this->notice('Your account is deleted', 'Your forms and submissions are gone and nothing more will be charged.');
    }

    private function notice(string $title, string $text, int $status = 200)
    {
        return response()->view('recipient', ['state' => 'notice', 'title' => $title, 'text' => $text], $status);
    }
}
