<?php

namespace App\Http\Controllers;

use App\Actions\BlockSender;
use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * "Block this sender" in the owner's email about a message. Opening it only shows a button and the
 * block happens on the POST, because mail scanners open every link in an email on arrival.
 * The signature is the check, as with the Monday summary's "Turn it off".
 */
class BlockSenderController extends Controller
{
    public function __invoke(Request $request, int $submission, BlockSender $block)
    {
        if (! URL::hasCorrectSignature($request)) {
            return $this->page('This link is not complete', 'Part of the link is missing or changed. Open it again from the email, or mark the message as spam in your dashboard.', 403);
        }

        $submission = Submission::with('form')->find($submission);

        if (! $submission) {
            return $this->page('Already gone', 'This message was deleted, so there is nothing to move. Block the sender under Settings on the form, as a spam word.');
        }

        $sender = $request->query('sender');
        $sender = is_string($sender) && $sender !== '' ? $sender : null;

        if ($request->isMethod('post')) {
            return $this->page('Sender blocked', $block->block($submission, $sender));
        }

        return response()->view('recipient', [
            'state' => 'notice',
            'title' => $sender ? 'Block '.$sender.'?' : 'Move this message to spam?',
            'text' => $sender
                ? 'This message on '.$submission->form->name.' moves to spam, and new messages from '.$sender.' go to spam too. Nothing is deleted: they wait in the spam list in your dashboard.'
                : 'This message on '.$submission->form->name.' moves to the spam list in your dashboard. It has no email address, so there is no sender to block.',
            'action' => $sender ? 'Block '.$sender : 'Move to spam',
        ]);
    }

    private function page(string $title, string $text, int $status = 200)
    {
        return response()->view('recipient', ['state' => 'notice', 'title' => $title, 'text' => $text], $status);
    }
}
