<?php

namespace App\Models;

use App\Mail\UsageNotice;
use App\Support\Umami;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;
use Laravel\Sanctum\HasApiTokens;

// Verified email is required: submissions are mailed to it, so an unverified
// address would let anyone point form spam at someone else's inbox.
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use Billable, HasApiTokens, HasFactory, Notifiable;

    protected static function booted(): void
    {
        // Every new account on the hosted service starts on a no-card trial. A self-hosted install has none.
        static::creating(function (User $user) {
            if (\App\Support\Mode::hosted()) {
                $user->trial_ends_at ??= now()->addDays(config('billing.trial_days'));
            }
        });

        // Counts the signup in Umami (App\Support\Umami): the moment an account is
        // made, whichever way (form, Google, API), without naming anyone.
        static::created(function () {
            Umami::track(request(), 'signup', '/register', ['method' => Umami::signupMethod(request())]);
        });

        // A deleted account must stop being charged. Stripe is asked first: if it fails,
        // the exception stops the delete, so nobody ends up billed for an account that is gone.
        static::deleting(function (User $user) {
            $user->cancelSubscriptionsNow();

            // Forms and submissions go by foreign key; these tables have none, so they are
            // cleared here. Invoices stay at Stripe, which tax law makes us keep.
            $user->subscriptions()->each(function ($subscription) {
                $subscription->items()->delete();
                $subscription->delete();
            });
            $user->tokens()->delete();
            \App\Models\OAuthRefreshToken::where('user_id', $user->id)->delete();
            \App\Models\OAuthCode::where('user_id', $user->id)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });

        // Set once, by whichever path verifies the address (the link, Google, the admin), and kept
        // after an email change: it decides which account-mail pool the account draws from.
        static::saving(function (User $user) {
            if ($user->email_verified_at && ! $user->first_verified_at) {
                $user->first_verified_at = $user->email_verified_at;
            }
        });

        // Whichever way an account goes (settings, the emailed link, the admin page).
        static::deleted(fn (User $user) => \App\Support\SecurityLog::event('account.deleted', ['user_id' => $user->id]));
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    /** The database default too: a new model must not read null for it before it is reloaded. */
    protected $attributes = [
        'has_password' => true,
        'weekly_digest' => true,
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'first_verified_at' => 'datetime',
            'password' => 'hashed',
            'trial_ends_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'has_password' => 'boolean',
            'weekly_digest' => 'boolean',
            'digest_sent_at' => 'datetime',
        ];
    }

    /** The link in the Monday email that turns it off. Never expires: it sits in every one we send. */
    public function digestStopUrl(): string
    {
        return \Illuminate\Support\Facades\URL::signedRoute('digest.stop', $this);
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }

    /** Ends every live subscription at Stripe now, without waiting for the period to run out. */
    public function cancelSubscriptionsNow(): void
    {
        $this->subscriptions()->where('stripe_status', '!=', 'canceled')->get()
            ->each(fn ($subscription) => $subscription->cancelNow());
    }

    public function forms(): HasMany
    {
        return $this->hasMany(Form::class);
    }

    /** Ability on a key that reaches one form only: the setup key an agent got from POST /api/v1/start. */
    public const FORM_ABILITY = 'form:';

    /**
     * The one form the key in use is limited to, or null when it reaches the whole account
     * (an API key from the dashboard, a session, or no token at all).
     */
    public function keyFormScope(): ?string
    {
        $token = $this->currentAccessToken();

        // "*": a dashboard API key. "mcp:use": a person signed an MCP client in with OAuth and agreed to the whole account.
        if (! $token instanceof \Laravel\Sanctum\PersonalAccessToken || array_intersect(['*', 'mcp:use'], $token->abilities ?? []) !== []) {
            return null;
        }

        foreach ($token->abilities ?? [] as $ability) {
            if (str_starts_with($ability, self::FORM_ABILITY)) {
                return substr($ability, strlen(self::FORM_ABILITY));
            }
        }

        // A key with neither "*" nor a form reaches nothing; an id no form has does exactly that.
        return '';
    }

    /**
     * The forms the API and MCP may touch with the key in use: every form for a full key, only
     * its own form for an agent's setup key. Without this, someone who starts a form and sends
     * the activation link to an existing customer would read every client's submissions.
     */
    public function formsForKey(): HasMany
    {
        $scope = $this->keyFormScope();

        return $scope === null ? $this->forms() : $this->forms()->where('public_id', $scope);
    }

    /**
     * Forms grouped by site host ('' for forms without a website, listed last), each with
     * this month's real submissions, its latest one and whether a real message ever came.
     * One query feeds the dashboard's sidebar and overview, however many forms there are.
     */
    public function formsBySite(): Collection
    {
        $real = fn ($query) => $query->where('spam', false);

        return $this->forms()
            ->withCount(['submissions as month_count' => fn ($query) => $real($query)->where('created_at', '>=', now()->startOfMonth())])
            ->withExists(['submissions as has_message' => $real])
            ->withMax(['submissions as last_submission_at' => $real], 'created_at')
            // Form::health(), behind each form's status badge, reads these.
            ->with(['recipients', 'deliveryFailures'])
            ->orderBy('name')
            ->get()
            ->each(fn (Form $form) => $form->setRelation('user', $this))
            ->groupBy(fn (Form $form) => $form->siteHost() ?? '')
            ->sortKeysUsing(fn (string $a, string $b) => [$a === '', $a] <=> [$b === '', $b]);
    }

    /**
     * The business's own account, which runs the contact forms on the other sites it owns. Listed in
     * OWNER_EMAILS on the server, never in the repo. Owners pay nothing, have no form cap and never pause.
     */
    public function isOwner(): bool
    {
        return in_array(strtolower((string) $this->email), config('billing.owner_emails'), true) && $this->hasVerifiedEmail();
    }

    /** A valid subscription on the per-form price, monthly or yearly. */
    public function isPaying(): bool
    {
        $subscription = $this->subscription('default');
        $prices = array_filter([config('billing.per_form.price_monthly'), config('billing.per_form.price_yearly')]);

        return $subscription?->valid() && in_array($subscription->stripe_price, $prices, true);
    }

    /**
     * Which limits apply now: 'owner', 'paid', 'trial', or null when the trial ended and nothing is paid.
     * On a self-hosted install always 'self_hosted': no bill, no trial, no limits, nothing ever paused.
     * Every limit, pause and usage notice reads plan(), so this is the one place that switches them off.
     */
    public function planKey(): ?string
    {
        return match (true) {
            \App\Support\Mode::selfHosted() => 'self_hosted',
            $this->isOwner() => 'owner',
            $this->isPaying() => 'paid',
            $this->onGenericTrial() => 'trial',
            default => null,
        };
    }

    /**
     * The limits that apply now, or null when the trial ended and nothing is paid.
     * forms: the cap on forms (null: none, every new form adds a unit to the bill).
     * submissions: the real submissions this month's units include, pooled over every form
     * (null on a self-hosted install: no limit at all).
     */
    public function plan(): ?array
    {
        $perForm = config('billing.per_form');

        return match ($this->planKey()) {
            'self_hosted' => ['key' => 'self_hosted', 'name' => 'Self-hosted', 'forms' => null, 'submissions' => null],
            'owner' => ['key' => 'owner', 'name' => 'Owner', 'forms' => null, 'submissions' => config('billing.owner_submissions')],
            'paid' => ['key' => 'paid', 'name' => 'Per form', 'forms' => null, 'submissions' => $perForm['submissions'] * $this->poolUnits(now())],
            'trial' => ['key' => 'trial', 'name' => 'Trial', 'forms' => config('billing.trial_forms'), 'submissions' => $perForm['submissions'] * min($this->formUnits(), max($perForm['min_forms'], config('billing.trial_forms')))],
            default => null,
        };
    }

    /** The forms the bill counts: every form, but never fewer than the minimum. This is the Stripe quantity. */
    public function formUnits(): int
    {
        return max(config('billing.per_form.min_forms'), $this->forms()->count());
    }

    /**
     * The form units the account is billed for right now: what Stripe last confirmed, not the form
     * count. The quantity sync waits (config sync_delay_minutes), so forms made and deleted inside
     * that wait are never billed and must not add to the pool either.
     */
    public function billedUnits(): int
    {
        return $this->isPaying()
            ? max(config('billing.per_form.min_forms'), (int) $this->subscription('default')->quantity)
            : $this->formUnits();
    }

    /**
     * The form units a paying account's pool is built from for the month of $at: the most it had
     * that month (MonthlyUsage peak_units), so a form deleted on the 30th does not shrink a pool its
     * submissions already filled. This month also counts the units billed now; a past month with no
     * recorded peak falls back to the minimum.
     */
    public function poolUnits(\Carbon\CarbonInterface $at): int
    {
        $peak = (int) MonthlyUsage::where('user_id', $this->id)->where('month', MonthlyUsage::monthOf($at))->value('peak_units');
        $now = MonthlyUsage::monthOf($at) === MonthlyUsage::monthOf(now()) ? $this->billedUnits() : config('billing.per_form.min_forms');

        return max($peak, $now);
    }

    /** Thousands of submissions past the month's pool, added to the next invoice: 0 when the month stayed inside it. */
    public function extraUnits(int $submissions, ?\Carbon\CarbonInterface $at = null): int
    {
        $pool = config('billing.per_form.submissions') * $this->poolUnits($at ?? now());

        return $submissions > $pool ? (int) ceil(($submissions - $pool) / config('billing.per_form.submissions')) : 0;
    }

    public function canCreateForm(): bool
    {
        $plan = $this->plan();

        return $plan !== null && ($plan['forms'] === null || $this->forms()->count() < $plan['forms']);
    }

    /**
     * Real (non-spam) submissions across all forms since the 1st of this month, from the counter
     * (MonthlyUsage), not the rows: a form with short retention prunes its rows within the month.
     */
    /**
     * Which daily account-mail pool this account's emails come out of: an account that never proved
     * its address can be made by anyone, so it shares the signup pool; one that did has its own.
     */
    public function accountMailPool(): string
    {
        return $this->first_verified_at ? \App\Support\MailBudget::ACCOUNT : \App\Support\MailBudget::SIGNUP;
    }

    /** Account mail comes out of its pool (App\Support\AccountMail); none left: not sent. */
    public function sendEmailVerificationNotification(): void
    {
        if (\App\Support\AccountMail::take('verify', $this)) {
            parent::sendEmailVerificationNotification();
        }
    }

    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        if (\App\Support\AccountMail::take('password_reset', $this)) {
            \App\Support\SecurityLog::event('password.reset_link_sent', ['user_id' => $this->id]);
            parent::sendPasswordResetNotification($token);
        }
    }

    public function submissionsThisMonth(): int
    {
        return MonthlyUsage::count($this->id, now());
    }

    public function overSubmissionLimit(): bool
    {
        $plan = $this->plan();

        return $plan !== null && $plan['submissions'] !== null && $this->submissionsThisMonth() >= $plan['submissions'];
    }

    /**
     * Submissions this month after which emails stop: the limit plus the grace share. Null when they
     * never stop: a paying account goes on, and pays per extra thousand on its next invoice; a
     * self-hosted install has no limit.
     */
    public function emailCutoff(): ?int
    {
        $plan = $this->plan();

        return $plan && $plan['submissions'] !== null && $plan['key'] !== 'paid' ? (int) ceil($plan['submissions'] * (1 + config('billing.email_grace'))) : null;
    }

    public function emailsPaused(): bool
    {
        $cutoff = $this->emailCutoff();

        return $cutoff !== null && $this->submissionsThisMonth() >= $cutoff;
    }

    /**
     * Ids of the forms the limits cover, oldest first; null when every form is covered.
     * Only a trial has a form cap, and it stops new forms, so this matters only for an
     * account that had more forms than the cap before it (an owner address removed).
     */
    public function coveredFormIds(): ?array
    {
        $plan = $this->plan();

        if ($plan === null) {
            return [];
        }

        if ($plan['forms'] === null) {
            return null;
        }

        return $this->forms()->orderBy('id')->limit($plan['forms'])->pluck('id')->all();
    }

    public function pausedFormsCount(): int
    {
        $covered = $this->coveredFormIds();

        return $covered === null ? 0 : max(0, $this->forms()->count() - count($covered));
    }

    /**
     * Email the owner once per month for the highest usage level just crossed.
     * Stored as "YYYY-MM:level" so a new month starts over.
     */
    public function sendUsageNoticeIfDue(): void
    {
        $plan = $this->plan();

        // No limit (a self-hosted install), so nothing to warn about.
        if ($plan === null || $plan['submissions'] === null || ! $this->hasVerifiedEmail()) {
            return;
        }

        // Levels in rising order, e.g. ['80' => 800, '100' => 1000, 'stopped' => 1100].
        $levels = [];
        foreach (config('billing.usage_notices') as $percent) {
            $levels[(string) $percent] = (int) ceil($plan['submissions'] * $percent / 100);
        }
        if ($this->emailCutoff() !== null) {
            $levels['stopped'] = $this->emailCutoff();
        }
        $keys = array_keys($levels);

        $used = $this->submissionsThisMonth();
        $crossed = -1;
        foreach (array_values($levels) as $rank => $threshold) {
            if ($used >= $threshold) {
                $crossed = $rank;
            }
        }

        $month = now()->format('Y-m');
        [$sentMonth, $sentLevel] = array_pad(explode(':', (string) $this->usage_notice, 2), 2, null);
        // Loose search on purpose: PHP turns the '80' key into int 80, the stored level is a string.
        $sent = $sentMonth === $month ? array_search($sentLevel, $keys) : false;

        if ($crossed < 0 || ($sent !== false && $crossed <= $sent)) {
            return;
        }

        $level = (string) $keys[$crossed];
        $this->forceFill(['usage_notice' => $month.':'.$level])->save();

        Mail::to($this->email)->queue(new UsageNotice($this, $level, $used));
    }
}
