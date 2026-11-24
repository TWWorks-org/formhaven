<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\SlackMessage;
use App\Support\WebhookTarget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Form extends Model
{
    use HasFactory;

    /** Must equal the retention_days column default in database/migrations/2026_10_27_000001_create_forms_and_submissions_tables.php. */
    public const DEFAULT_RETENTION_DAYS = 30;

    protected $fillable = ['name', 'site_url', 'redirect_url', 'retention_days', 'store_submissions', 'webhook_url', 'notify_owner', 'sender_name', 'allowed_domains', 'bot_check', 'spam_words', 'recipients_can_view', 'recipients_weekly_summary'];

    /** The columns' defaults, so a form made in memory does not read as "store nothing" or "mail nobody" before it is reloaded. */
    protected $attributes = ['retention_days' => self::DEFAULT_RETENTION_DAYS, 'store_submissions' => true, 'notify_owner' => true, 'bot_check' => false, 'recipients_can_view' => false, 'recipients_weekly_summary' => true];

    /** The DidMyFormSend key is set only through FormSettings and shown only as its prefix (toApi()'s "monitor"). */
    protected $hidden = ['monitor_key'];

    protected function casts(): array
    {
        return ['store_submissions' => 'boolean', 'notify_owner' => 'boolean', 'webhook_last_at' => 'datetime', 'allowed_domains' => 'array', 'bot_check' => 'boolean', 'spam_words' => 'array', 'last_message_at' => 'datetime', 'recipients_can_view' => 'boolean', 'recipients_weekly_summary' => 'boolean', 'monitor_key' => 'encrypted', 'monitor_refused_at' => 'datetime'];
    }

    /**
     * POSTs one webhook body, signed, and records the outcome on the form for its page.
     * The signature header is "t=<unix time>,v1=<hex HMAC-SHA256 of "<t>.<body>" with the secret>".
     */
    public function deliverWebhook(string $body): bool
    {
        $record = fn (?int $status, ?string $error) => $this->forceFill([
            'webhook_last_status' => $status,
            'webhook_last_error' => $error === null ? null : Str::limit($error, 480),
            'webhook_last_at' => now(),
        ])->save();

        try {
            $target = WebhookTarget::check((string) $this->webhook_url);
        } catch (\InvalidArgumentException $e) {
            $record(null, $e->getMessage());

            return false;
        }

        if (SlackMessage::isSlack($target)) {
            $body = SlackMessage::fromWebhookBody($body);
        }

        $time = time();

        try {
            $response = Http::timeout(10)->connectTimeout(5)
                ->withOptions(['allow_redirects' => false, 'curl' => $target->pin()])
                ->withHeaders([
                    'User-Agent' => 'FormHaven-Webhook/1 (+'.config('app.url').')',
                    'FormHaven-Signature' => "t={$time},v1=".hash_hmac('sha256', "{$time}.{$body}", (string) $this->webhook_secret),
                ])
                ->withBody($body, 'application/json')
                ->post($target->url);
        } catch (ConnectionException $e) {
            $record(null, 'Could not reach the webhook address: '.$e->getMessage());

            return false;
        }

        $record($response->status(), $response->successful() ? null : 'Your endpoint answered HTTP '.$response->status().'. Anything other than a 2xx counts as failed.');

        return $response->successful();
    }

    protected static function booted(): void
    {
        static::creating(function (Form $form) {
            $form->public_id ??= Str::lower(Str::random(16));
        });

        // The bill follows the number of forms (config/billing.php), so every add or delete re-syncs it,
        // an hour later: a form created and deleted inside that hour never reaches Stripe.
        // A self-hosted install has no bill to sync.
        $sync = fn (Form $form) => \App\Support\Mode::hosted()
            ? \App\Jobs\SyncFormQuantity::dispatch($form->user_id)
                ->delay(now()->addMinutes(config('billing.per_form.sync_delay_minutes')))
                ->afterCommit()
            : null;
        static::created($sync);
        static::deleted($sync);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(FormRecipient::class)->orderBy('id');
    }

    /** Emails our mail provider did not deliver, oldest first. */
    public function deliveryFailures(): HasMany
    {
        return $this->hasMany(DeliveryFailure::class)->orderBy('id');
    }

    /** The From name on emails to recipients: what the owner set, else their account name. */
    public function senderName(): string
    {
        // The account name fallback was never checked like sender_name is, and both end up in a From header.
        $name = trim(preg_replace(['/[<>"\\\\]/', '/[\x00-\x1f\x7f]+/', '/\s+/'], ['', ' ', ' '], (string) ($this->sender_name ?: $this->user->name)));

        return Str::limit($name, 60, '') ?: config('app.name');
    }

    /**
     * Who a real submission is emailed to: the confirmed recipients, plus the owner
     * unless they turned that off. With no recipient confirmed the owner always gets
     * it, so switching yourself off before your client clicks never loses a message.
     * An owner who has not verified their address is skipped.
     *
     * @return list<FormRecipient|null> null stands for the owner
     */
    public function mailTargets(): array
    {
        $confirmed = $this->recipients()->whereNotNull('confirmed_at')->get()->all();
        $owner = ($this->notify_owner || $confirmed === []) && $this->user->hasVerifiedEmail() ? [null] : [];

        return [...$owner, ...$confirmed];
    }

    public function endpoint(): string
    {
        return route('submit', $this->public_id);
    }

    /** The owner's site as scheme and host, for the "Back to" link on our pages; null when not set. */
    public function siteOrigin(): ?string
    {
        $url = parse_url((string) $this->site_url);

        if (! isset($url['scheme'], $url['host']) || ! in_array($url['scheme'], ['http', 'https'], true)) {
            return null;
        }

        return $url['scheme'].'://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '');
    }

    /** The saved site's host without "www.", which the dashboard groups forms under; null when no site is saved. */
    public function siteHost(): ?string
    {
        $host = parse_url((string) $this->site_url, PHP_URL_HOST);

        return $host ? preg_replace('/^www\./', '', strtolower($host)) : null;
    }

    /** Most sites an owner can list; enough for an agency's staging, live and old domains. */
    public const MAX_ALLOWED_DOMAINS = 20;

    /**
     * What an owner typed ("https://www.Example.nl/contact", "*.example.nl") as the host we store
     * ("example.nl"); null when it is not a domain. A leading "www." or "*." is dropped because a
     * listed domain already covers its subdomains.
     */
    public static function normalizeDomain(string $typed): ?string
    {
        $typed = strtolower(trim($typed));
        $host = parse_url(str_contains($typed, '://') ? $typed : 'http://'.$typed, PHP_URL_HOST);
        $host = preg_replace('/^(\*\.|www\.)/', '', rtrim((string) $host, '.'));

        return $host !== '' && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) && str_contains($host, '.') ? $host : null;
    }

    /**
     * Null when the post may come in, else why it goes to spam: "not_allowed:<host>" or "no_origin".
     * The browser's Origin header comes first, then the Referer; both can be forged, so this keeps a
     * copied form address off other sites, it does not prove who sent it. Localhost always passes, so
     * an owner can test before deploying.
     */
    public function senderRefusal(?string $origin, ?string $referer): ?string
    {
        if (! $this->allowed_domains) {
            return null;
        }

        // A sandboxed frame or a file:// page sends the literal "null".
        $host = parse_url($origin && $origin !== 'null' ? $origin : (string) $referer, PHP_URL_HOST);

        if (! $host) {
            return 'no_origin';
        }

        $host = strtolower(trim($host, '[]'));

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true) || str_ends_with($host, '.localhost')) {
            return null;
        }

        foreach ($this->allowed_domains as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return null;
            }
        }

        return 'not_allowed:'.Str::limit($host, 253, '');
    }

    /** Every code health() can return; llms.txt documents each one, and a test holds it to that. */
    public const HEALTH_CODES = ['UNDELIVERED', 'CONFIRMATION_FAILED', 'RECIPIENT_UNCONFIRMED', 'OWNER_UNVERIFIED', 'MONITOR_KEY_REFUSED', 'QUIET'];

    /** Fewer real messages than this is not a rhythm yet, so the form is never called quiet. */
    public const QUIET_MIN_MESSAGES = 5;

    /** Never call a form quiet sooner than this. */
    public const QUIET_MIN_DAYS = 7;

    /** Quiet means this many times the form's usual gap between messages. */
    public const QUIET_GAP_FACTOR = 4;

    /** A real message arrived: keep the running numbers the quiet check reads. No extra query. */
    public function noteMessage(\Illuminate\Support\Carbon $at): void
    {
        $gap = $this->last_message_at ? max(0, (int) round($this->last_message_at->diffInHours($at))) : null;

        $this->forceFill([
            'typical_gap_hours' => match (true) {
                $gap === null => $this->typical_gap_hours,
                $this->typical_gap_hours === null => $gap,
                default => (int) round(0.8 * $this->typical_gap_hours + 0.2 * $gap),
            },
            'messages_total' => $this->messages_total + 1,
            'last_message_at' => $at,
        ])->save();
    }

    /** Days without a message, when that is unusual for this form; else null. Nothing is stored: a message clears it. */
    public function quietDays(): ?int
    {
        if ($this->messages_total < self::QUIET_MIN_MESSAGES || ! $this->last_message_at) {
            return null;
        }

        $silent = $this->last_message_at->diffInHours(now());
        $limit = max(self::QUIET_MIN_DAYS * 24, self::QUIET_GAP_FACTOR * (int) $this->typical_gap_hours);

        // withinPlan() queries, so it runs only for the rare form that is past its limit.
        return $silent > $limit && $this->withinPlan() ? (int) floor($silent / 24) : null;
    }

    /** "12 hours" or "3 days": how often this form usually gets a message. */
    public function typicalGap(): string
    {
        $hours = max(1, (int) $this->typical_gap_hours);

        return $hours < 48 ? $hours.' '.($hours === 1 ? 'hour' : 'hours') : (int) round($hours / 24).' days';
    }

    /** Days after which a recipient that has not pressed its link counts as a problem. */
    public const UNCONFIRMED_DAYS = 3;

    /**
     * What stops this form's messages reaching people, each as a sentence the owner can act on.
     * The dashboard, REST and MCP all show this, so an agent can tell the site owner what to fix.
     * Lists of forms eager-load recipients and deliveryFailures, so this runs no query per form.
     *
     * @return array{status: 'ok'|'attention'|'quiet', problems: list<array{code: string, email: ?string, message: string, fix: string}>}
     */
    public function health(): array
    {
        $problems = [];
        $failures = $this->deliveryFailures;

        foreach ($failures->whereNotNull('submission_id')->groupBy('email') as $email => $group) {
            $count = $group->count();
            $problems[] = ['code' => 'UNDELIVERED', 'email' => $email,
                'message' => $count.' '.($count === 1 ? 'message' : 'messages').' did not reach '.$email.'. '.$group->last()->explain(),
                'fix' => $group->last()->fix()];
        }

        foreach ($failures->whereNull('submission_id')->unique('email') as $failure) {
            $problems[] = ['code' => 'CONFIRMATION_FAILED', 'email' => $failure->email,
                'message' => 'The confirmation email to '.$failure->email.' did not arrive. '.$failure->explain(),
                'fix' => 'Check the spelling. Remove the address and add the right one, or press Resend link once the mailbox works.'];
        }

        $failedConfirmations = $failures->whereNull('submission_id')->pluck('form_recipient_id')->all();
        foreach ($this->recipients as $recipient) {
            if (! $recipient->isConfirmed() && $recipient->created_at->lt(now()->subDays(self::UNCONFIRMED_DAYS)) && ! in_array($recipient->id, $failedConfirmations, true)) {
                $problems[] = ['code' => 'RECIPIENT_UNCONFIRMED', 'email' => $recipient->email,
                    'message' => $recipient->email.' has not confirmed yet, so it gets no messages.',
                    'fix' => 'Ask them to press the link in our email, or send it again.'];
            }
        }

        if (! $this->user->hasVerifiedEmail() && $this->recipients->whereNotNull('confirmed_at')->isEmpty()) {
            $problems[] = ['code' => 'OWNER_UNVERIFIED', 'email' => $this->user->email,
                'message' => 'Nobody is emailed: your own address is not verified yet.',
                'fix' => 'Press the link in the email we sent you, or send it again from Settings.'];
        }

        if ($this->monitor_refused_at !== null && \App\Support\DidMyFormSend::enabled($this)) {
            $problems[] = ['code' => 'MONITOR_KEY_REFUSED', 'email' => null,
                'message' => 'DidMyFormSend refused the key saved on this form, so the monitor is not receiving reports about its emails.',
                'fix' => 'Paste a current key from the monitor\'s page on didmyformsend.com, or clear the DidMyFormSend key field under Settings.'];
        }

        $urgent = $problems !== [];

        if ($days = $this->quietDays()) {
            $problems[] = ['code' => 'QUIET', 'email' => null,
                'message' => 'No messages for '.$days.' days. This form usually gets one every '.$this->typicalGap().'.',
                'fix' => 'Send a test from the site itself to check the form still works.'];
        }

        return ['status' => $urgent ? 'attention' : ($problems === [] ? 'ok' : 'quiet'), 'problems' => $problems];
    }

    /** 'paused', 'attention', 'quiet', 'waiting' (no real message yet) or 'active', for the dashboard's badges. */
    public function dashboardStatus(?array $covered): string
    {
        $paused = $covered !== null && ! in_array($this->id, $covered, true);
        $health = $paused ? null : $this->health()['status'];

        return match (true) {
            $paused => 'paused',
            in_array($health, ['attention', 'quiet'], true) => $health,
            ! ($this->has_message ?? $this->submissions()->where('spam', false)->exists()) => 'waiting',
            default => 'active',
        };
    }

    /**
     * A visitor-sent _next address (Formspree's field), or null when it may not be used.
     * Only pages on the owner's saved site or on the site the form was sent from qualify,
     * so nobody can use our domain to bounce visitors to a site of their choosing.
     */
    public function acceptedNext(?string $next, ?string $referer): ?string
    {
        $host = fn (?string $url) => ($h = parse_url((string) $url, PHP_URL_HOST)) ? preg_replace('/^www\./', '', strtolower($h)) : null;

        if (! is_string($next)) {
            return null;
        }

        // A relative address ("/bedankt", Formspark allows these) opens on the site the form was sent
        // from, else the saved site. "//host" and "scheme:" are not relative and are judged below as they are.
        if ($next !== '' && ! preg_match('#^([a-z][a-z0-9+.\-]*:|//)#i', $next)) {
            $base = parse_url((string) ($referer ?: $this->site_url));
            if (! isset($base['scheme'], $base['host'])) {
                return null;
            }
            $dir = str_starts_with($next, '/') ? '' : preg_replace('#/[^/]*$#', '', $base['path'] ?? '').'/';
            $next = $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '').$dir.$next;
        }

        // Backslashes, spaces and control characters are where PHP and browsers disagree
        // on the host ("https://evil.example\@client-site.nl" opens evil.example), so refuse them.
        if (preg_match('/[\\\\\s\x00-\x1f\x7f]/', $next)) {
            return null;
        }

        $url = parse_url($next);

        if ($url === false || ! in_array($url['scheme'] ?? null, ['http', 'https'], true) || ! isset($url['host'])
            || isset($url['user']) || isset($url['pass'])
            || ! in_array($host($next), array_filter([$host($this->site_url), $host($referer)]), true)) {
            return null;
        }

        // Rebuilt from the checked parts, so the browser gets exactly the URL that was checked.
        return $url['scheme'].'://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '')
            .($url['path'] ?? '').(isset($url['query']) ? '?'.$url['query'] : '').(isset($url['fragment']) ? '#'.$url['fragment'] : '');
    }

    /** The one line a site adds for the bot check, the same for every form. */
    public static function botCheckScript(): string
    {
        return '<script src="'.url('/bot-check.js').'" async></script>';
    }

    /** False for a form beyond the owner's plan (after a downgrade) or when no plan is active. */
    public function withinPlan(): bool
    {
        $covered = $this->user->coveredFormIds();

        return $covered === null || in_array($this->id, $covered, true);
    }

    /** @return array{on: bool, key_prefix: ?string} */
    public function monitorSummary(): array
    {
        $on = \App\Support\DidMyFormSend::enabled($this);

        // A key the app key can no longer decrypt still reads as on, with no prefix.
        return ['on' => $on, 'key_prefix' => $on ? rescue(fn () => \App\Support\DidMyFormSend::prefix((string) $this->monitor_key), null, false) : null];
    }

    /** The shape the API and MCP return. */
    public function toApi(): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'endpoint' => $this->endpoint(),
            'site_url' => $this->site_url,
            'redirect_url' => $this->redirect_url,
            'retention_days' => $this->retention_days,
            'store_submissions' => $this->store_submissions,
            // The signing secret is never in here: it is returned once, when a webhook is first set.
            'webhook_url' => $this->webhook_url,
            // Empty: any site may post. Else only these domains and their subdomains; others go to spam.
            'allowed_domains' => $this->allowed_domains ?: [],
            // On: posts need the solved puzzle from the bot-check script, else they go to spam.
            'bot_check' => $this->bot_check,
            'bot_check_script' => self::botCheckScript(),
            'spam_words' => $this->spam_words ?: [],
            'paused' => ! $this->withinPlan(),
            'notify_owner' => $this->notify_owner,
            'sender_name' => $this->sender_name,
            // On: each confirmed recipient's emails link to a read-only page of this form's messages (its "messages_url").
            'recipients_can_view' => $this->recipients_can_view,
            // On: each confirmed recipient gets a weekly email with last week's count and its messages_url.
            // Sent only while recipients_can_view is on too, and never to an address that stopped it.
            'recipients_weekly_summary' => $this->recipients_weekly_summary,
            'recipients' => $this->recipients->map(fn (FormRecipient $recipient) => $recipient
                ->setRelation('deliveryFailures', $this->deliveryFailures->where('form_recipient_id', $recipient->id)->values())
                ->toApi($this))->all(),
            // The DidMyFormSend key is never returned: only whether one is saved and its first 8 characters.
            'monitor' => $this->monitorSummary(),
            // What stops messages reaching people, with the fix; status "ok" when nothing does.
            'health' => $this->health(),
            'submissions' => $this->submissions_count ?? $this->submissions()->where('spam', false)->count(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
