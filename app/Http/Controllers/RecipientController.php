<?php

namespace App\Http\Controllers;

use App\Models\FormRecipient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * The links a recipient gets: confirm, stop, and stop the weekly count. All are signed. Opening a link
 * only shows a button; the change happens on the POST, because mail scanners
 * (Outlook Safe Links and the like) open every link in an email on arrival.
 * The signature is checked here rather than by middleware, so a person with an
 * expired or broken link reads what to do instead of a bare 403.
 */
class RecipientController extends Controller
{
    public function confirm(Request $request, int $recipient)
    {
        if ($problem = $this->linkProblem($request)) {
            return $problem;
        }

        $recipient = FormRecipient::find($recipient);

        if (! $recipient) {
            return $this->notice('Link no longer works', 'This address was removed from the form, so there is nothing to confirm. Ask the person who added you to add it again.', 404);
        }

        if ($request->isMethod('post') && ! $recipient->isConfirmed()) {
            $recipient->forceFill(['confirmed_at' => now()])->save();
        }

        return $this->page($recipient, $recipient->isConfirmed() ? 'confirmed' : 'confirm');
    }

    public function stop(Request $request, int $recipient)
    {
        if ($problem = $this->linkProblem($request)) {
            return $problem;
        }

        $recipient = FormRecipient::find($recipient);

        // Pressed twice, or removed by the form's owner in the meantime: the outcome they wanted.
        if (! $recipient) {
            return $this->notice('Stopped', 'This address gets no more messages from this form.');
        }

        if ($request->isMethod('post')) {
            $form = $recipient->form;
            $email = $recipient->email;
            $recipient->delete();

            return view('recipient', ['state' => 'stopped', 'form' => $form, 'email' => $email]);
        }

        return $this->page($recipient, 'stop');
    }

    /**
     * Stops the weekly count and nothing else, so a client who does not want the summary keeps
     * getting every message. The mail app's one-click unsubscribe posts here too.
     */
    public function stopSummary(Request $request, int $recipient)
    {
        if ($problem = $this->linkProblem($request)) {
            return $problem;
        }

        $recipient = FormRecipient::find($recipient);

        if (! $recipient) {
            return $this->notice('Stopped', 'This address gets no more emails from this form.');
        }

        $form = $recipient->form;

        if ($request->isMethod('post')) {
            if ($recipient->summary_stopped_at === null) {
                $recipient->forceFill(['summary_stopped_at' => now()])->save();
            }

            return $this->notice('Weekly count stopped', $recipient->email.' no longer gets the weekly count for '.$form->name.'. Emails about each message keep coming.');
        }

        return response()->view('recipient', [
            'state' => 'notice',
            'title' => 'Stop the weekly count?',
            'text' => 'We will stop sending '.$recipient->email.' the weekly count of messages from '.$form->name.'. Emails about each message keep coming.',
            'action' => 'Stop the weekly count',
        ]);
    }

    /** Messages listed per page on the recipient's messages page. */
    public const MESSAGES_PER_PAGE = 20;

    /**
     * A confirmed recipient's read-only page of the form's messages, when the owner turned it on.
     * Only reading and paging: no session, no cookies, nothing changes. Whether it opens is
     * decided on every visit, so switching the setting off or removing the address closes it.
     */
    public function messages(Request $request, int $recipient)
    {
        // Paging adds ?page=, which is not part of the signed link.
        if (! URL::hasCorrectSignature($request, true, ['page'])) {
            return $this->closed('This link is not complete', 'Part of the link is missing or changed. Open it again from one of your emails, or copy the whole address into your browser.', 403);
        }

        $recipient = FormRecipient::with('form.user')->find($recipient);

        if (! $recipient) {
            return $this->closed('This page is not available', 'This address was removed from the form, so its messages page is closed. Ask the person who made the website if you need it again.', 404);
        }

        $form = $recipient->form;
        $maker = $form->senderName();

        if (! $recipient->isConfirmed()) {
            return $this->closed('This page is not available', $recipient->email.' has not confirmed it wants messages from '.$form->name.' yet. Press the link in the first email about this form, or ask '.$maker.' to send it again.', 403);
        }

        if (! $recipient->canViewMessages($form)) {
            return $this->closed('This page is not available', $maker.' has not turned on this page for '.$form->name.'. Ask them if you want to read the messages here. Your emails are not affected.', 403);
        }

        $now = now();
        $keptSince = $now->copy()->subDays($form->retention_days);
        $thisMonth = $now->copy()->startOfMonth();
        $lastMonth = $thisMonth->copy()->subMonth();
        $real = fn () => $form->submissions()->where('spam', false);

        // Counted from the rows still stored, so a month that started before the retention window is only partly there.
        $counts = collect([
            ['label' => 'This month', 'from' => $thisMonth, 'to' => $now->copy()->addSecond()],
            ['label' => 'Last month', 'from' => $lastMonth, 'to' => $thisMonth],
        ])->map(fn (array $month) => [
            'label' => $month['label'],
            'count' => $real()->where('created_at', '>=', $month['from'])->where('created_at', '<', $month['to'])->count(),
            'partial' => $month['from']->lt($keptSince),
        ])->all();

        // A form that keeps nothing has no answers to show; its rows are empty records kept for counting.
        $messages = $form->store_submissions
            ? $real()->latest()->latest('id')->paginate(self::MESSAGES_PER_PAGE)->withQueryString()
            : null;

        return response()
            ->view('recipient-messages', [
                'form' => $form,
                'recipient' => $recipient,
                'maker' => $maker,
                'counts' => $counts,
                'messages' => $messages,
                // Messages arrived once but are all past the retention days: say so, rather than "none yet".
                'expired' => $form->last_message_at !== null && $form->last_message_at->lt($keptSince),
            ])
            ->withHeaders($this->privateHeaders());
    }

    /** The messages page and its refusals: never cached by a proxy, never indexed, and the signed link never sent on as a referrer. */
    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer'];
    }

    private function closed(string $title, string $text, int $status)
    {
        return $this->notice($title, $text, $status)->withHeaders($this->privateHeaders());
    }

    private function linkProblem(Request $request)
    {
        if (! URL::hasCorrectSignature($request)) {
            return $this->notice('This link is not complete', 'Part of the link is missing or changed. Open it again from the email, or copy the whole address into your browser.', 403);
        }

        if (! URL::signatureHasNotExpired($request)) {
            return $this->notice('This link has expired', 'Confirmation links work for '.FormRecipient::CONFIRM_DAYS.' days. Ask the person who added you to send a new one.', 403);
        }

        return null;
    }

    private function notice(string $title, string $text, int $status = 200)
    {
        return response()->view('recipient', ['state' => 'notice', 'title' => $title, 'text' => $text], $status);
    }

    private function page(FormRecipient $recipient, string $state)
    {
        return view('recipient', ['state' => $state, 'form' => $recipient->form, 'email' => $recipient->email]);
    }
}
