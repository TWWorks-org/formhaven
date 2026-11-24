<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * The "Turn it off" link in the Monday email. Opening it only shows a button and the change happens
 * on the POST, because mail scanners open every link in an email on arrival. Mail apps send the
 * List-Unsubscribe one-click POST without a session, so the signature is the only check.
 */
class DigestController extends Controller
{
    public function stop(Request $request, int $user)
    {
        if (! URL::hasCorrectSignature($request)) {
            return $this->page('This link is not complete', 'Part of the link is missing or changed. Open it again from the email, or turn the summary off under Settings in your dashboard.', 403);
        }

        $user = User::find($user);

        if (! $user) {
            return $this->page('Stopped', 'This account no longer exists, so no summary is sent to it.');
        }

        if ($request->isMethod('post')) {
            $user->forceFill(['weekly_digest' => false])->save();

            return $this->page('Weekly summary turned off', 'We no longer send it to '.$user->email.'. Emails about each message keep coming. Switch the summary back on under Settings.');
        }

        return response()->view('recipient', [
            'state' => 'notice',
            'title' => 'Turn off the weekly summary?',
            'text' => 'We will stop sending the Monday summary of your forms to '.$user->email.'. Emails about each message keep coming.',
            'action' => 'Turn it off',
        ]);
    }

    private function page(string $title, string $text, int $status = 200)
    {
        return response()->view('recipient', ['state' => 'notice', 'title' => $title, 'text' => $text], $status);
    }
}
