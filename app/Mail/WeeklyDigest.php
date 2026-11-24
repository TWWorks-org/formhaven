<?php

namespace App\Mail;

use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * The Monday summary to an account owner: what needs a look, then every form's messages last
 * week and the week before. Counted when it is sent, not when it is queued. It has no failed()
 * on purpose: a delivery alert about the summary itself would be a loop.
 */
class WeeklyDigest extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** A form keeping messages for fewer days than this cannot show the week before. */
    public const COMPARE_DAYS = 14;

    private ?array $figures = null;

    public function __construct(public User $user) {}

    /**
     * @return array{sites: Collection, problems: list<array{form: Form, problem: array}>, week: int, before: ?int, spam: int}
     */
    public function figures(): array
    {
        if ($this->figures !== null) {
            return $this->figures;
        }

        $sites = $this->user->formsBySite();
        $ids = $sites->flatten(1)->pluck('id');
        $count = fn (bool $spam, $from, $to) => Submission::whereIn('form_id', $ids)->where('spam', $spam)
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->selectRaw('form_id, count(*) as n')->groupBy('form_id')->pluck('n', 'form_id');

        $now = now();
        $week = $count(false, $now->copy()->subDays(7), $now);
        $before = $count(false, $now->copy()->subDays(14), $now->copy()->subDays(7));
        $spam = $count(true, $now->copy()->subDays(7), $now);

        $problems = [];
        foreach ($sites->flatten(1) as $form) {
            $form->digest_week = (int) ($week[$form->id] ?? 0);
            $form->digest_before = $form->store_submissions && $form->retention_days >= self::COMPARE_DAYS ? (int) ($before[$form->id] ?? 0) : null;
            $form->digest_spam = (int) ($spam[$form->id] ?? 0);

            foreach ($form->health()['problems'] as $problem) {
                $problems[] = ['form' => $form, 'problem' => $problem];
            }
        }

        $forms = $sites->flatten(1);

        return $this->figures = [
            'sites' => $sites,
            'problems' => $problems,
            'week' => $forms->sum('digest_week'),
            // Only when every form can show it; a partial total would read lower than it was.
            'before' => $forms->contains(fn ($form) => $form->digest_before === null) ? null : $forms->sum('digest_before'),
            'spam' => $forms->sum('digest_spam'),
        ];
    }

    public function envelope(): Envelope
    {
        $figures = $this->figures();
        $look = collect($figures['problems'])->pluck('form.id')->unique()->count();

        return new Envelope(subject: 'Your forms last week: '.$figures['week'].' '.($figures['week'] === 1 ? 'message' : 'messages')
            .($look ? ', '.$look.' '.($look === 1 ? 'form needs' : 'forms need').' a look' : ''));
    }

    /** One-click unsubscribe in the reader's mail app, as on a client's copy of a submission. */
    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->user->digestStopUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.weekly-digest', text: 'mail.weekly-digest-text', with: $this->figures() + ['stopUrl' => $this->user->digestStopUrl()]);
    }
}
