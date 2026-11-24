<?php

namespace App\Support;

use App\Mail\MailBudgetSpent;
use App\Mail\MailLimitReached;
use App\Models\DeliveryFailure;
use App\Models\Form;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Daily ceilings on the mail a stranger can cause (config mail.budget), counted per UTC day in
 * the cache. The quota is shared with other sites and a form's address is public, so without
 * these one copied address could use up a day's mail for every customer and every site.
 *
 * Pools, each with its own ceiling so none can take another's room:
 *  - notifications: every email about a submission, first send and Send again, across all forms,
 *    and per form on top of that.
 *  - summaries: the Monday summary and the recipients' weekly count.
 *  - abuse: abuse-report mail.
 *  - notices: the "limit reached" emails below, so they cannot loop or grow.
 *  - signup and account: account mail (App\Support\AccountMail, which also limits it per
 *    visitor and per address). Signup is for accounts that never proved their address, which
 *    anyone can make; account is for the rest, so a flood of signups cannot stop a real reset.
 *
 * A take counts up first and gives back when over, so two requests at once cannot both get the
 * last one (the cache increment is atomic on the database store).
 */
class MailBudget
{
    public const NOTIFICATIONS = 'notifications';

    public const SUMMARIES = 'summaries';

    public const ABUSE = 'abuse';

    public const NOTICES = 'notices';

    public const SIGNUP = 'signup';

    public const ACCOUNT = 'account';

    /** The config key under mail.budget for each pool. */
    private const LIMITS = [
        self::NOTIFICATIONS => 'notifications_per_day',
        self::SUMMARIES => 'summaries_per_day',
        self::ABUSE => 'abuse_reports_per_day',
        self::NOTICES => 'notices_per_day',
        self::SIGNUP => 'signup_per_day',
        self::ACCOUNT => 'account_per_day',
    ];

    /**
     * Takes one submission email for this form: null when it may go, else why it is held
     * (DeliveryFailure::HELD_FORM or HELD_ALL). The form's own ceiling is checked first, so a
     * form over its limit is told that, not that FormHaven is.
     */
    public static function takeForForm(Form $form): ?string
    {
        $formKey = self::key('form:'.$form->id);

        if (! self::take($formKey, self::perForm())) {
            self::noticeFormOwner($form);

            return DeliveryFailure::HELD_FORM;
        }

        if (! self::take(self::key(self::NOTIFICATIONS), self::limit(self::NOTIFICATIONS))) {
            Cache::decrement($formKey);
            self::noticeOperator();

            return DeliveryFailure::HELD_ALL;
        }

        // Which form sent the most today, for the operator's notice. Not atomic, and need not be.
        $used = (int) Cache::get($formKey, 0);
        if ($used > (int) (Cache::get(self::key('top'))['count'] ?? 0)) {
            Cache::put(self::key('top'), ['form_id' => $form->id, 'count' => $used], now()->addDays(2));
        }

        return null;
    }

    /** Takes $count mails from a pool (SUMMARIES, ABUSE, NOTICES, SIGNUP, ACCOUNT); false when that many are not left today. */
    public static function tryTake(string $pool, int $count = 1): bool
    {
        return self::take(self::key($pool), self::limit($pool), $count);
    }

    /** Mails left today in a pool. */
    public static function remaining(string $pool): int
    {
        return max(0, self::limit($pool) - (int) Cache::get(self::key($pool), 0));
    }

    public static function limit(string $pool): int
    {
        return max(0, (int) config('mail.budget.'.self::LIMITS[$pool]));
    }

    public static function perForm(): int
    {
        return max(0, (int) config('mail.budget.per_form_per_day'));
    }

    private static function take(string $key, int $limit, int $count = 1): bool
    {
        Cache::add($key, 0, now()->addDays(2));
        $used = (int) Cache::increment($key, $count);

        if ($used > $limit) {
            Cache::decrement($key, $count);

            return false;
        }

        return true;
    }

    /** One email a UTC day to the form's owner that its limit was reached. */
    private static function noticeFormOwner(Form $form): void
    {
        $owner = $form->user;

        // An unverified address may not be the owner's own: nothing is sent to it.
        if (! $owner->hasVerifiedEmail() || ! Cache::add(self::key('notice:form:'.$form->id), 1, now()->addDays(2))) {
            return;
        }

        Log::warning('Form reached its daily email limit', ['form' => $form->public_id, 'limit' => self::perForm()]);

        if (! self::tryTake(self::NOTICES)) {
            return;
        }

        Mail::to($owner->email)->queue(new MailLimitReached(
            formName: $form->name,
            formUrl: route('forms.show', $form),
            limit: self::perForm(),
        ));
    }

    /** One email a UTC day to the operator (OWNER_EMAILS) that the limit across all forms was reached. */
    private static function noticeOperator(): void
    {
        if (! Cache::add(self::key('notice:all'), 1, now()->addDays(2))) {
            return;
        }

        $top = Cache::get(self::key('top'));
        $form = $top ? Form::with('user')->find($top['form_id']) : null;

        Log::warning('Daily email limit for all forms reached', ['limit' => self::limit(self::NOTIFICATIONS), 'top_form' => $form?->public_id, 'top_count' => $top['count'] ?? null]);

        $to = config('billing.owner_emails');

        // A self-hosted install without OWNER_EMAILS tells its first verified account: on such an
        // install that is whoever set it up. The hosted service always lists its operator.
        if ($to === [] && Mode::selfHosted()) {
            $to = array_filter([\App\Models\User::whereNotNull('email_verified_at')->orderBy('id')->value('email')]);
        }

        if ($to === [] || ! self::tryTake(self::NOTICES, count($to))) {
            return;
        }

        Mail::to($to)->queue(new MailBudgetSpent(
            limit: self::limit(self::NOTIFICATIONS),
            topForm: $form ? $form->name.' ('.$form->public_id.', account '.$form->user->email.')' : null,
            topCount: (int) ($top['count'] ?? 0),
            perForm: self::perForm(),
        ));
    }

    private static function key(string $name): string
    {
        return 'mail-budget:'.now()->utc()->toDateString().':'.$name;
    }
}
