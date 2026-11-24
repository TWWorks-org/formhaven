<?php

use App\Actions\BlockSender;
use App\Actions\DeleteForm;
use App\Actions\FormEmails;
use App\Actions\FormSettings;
use App\Actions\FormSpamSettings;
use App\Actions\ReceiveSubmission;
use App\Actions\RetryDeliveries;
use App\Exceptions\SubmissionRefused;
use App\Models\Form;
use App\Models\FormRecipient;
use App\Models\Submission;
use App\Support\WebhookTarget;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public Form $form;

    public string $name = '';

    public string $site_url = '';

    public string $redirect_url = '';

    public int $retention_days = Form::DEFAULT_RETENTION_DAYS;

    public bool $store_submissions = true;

    public string $webhook_url = '';

    /** One domain per line (commas work too), as the owner types them. */
    public string $allowed_domains = '';

    public bool $bot_check = false;

    /** A DidMyFormSend key being pasted. A saved key is never put back in here: the page shows its prefix. */
    public string $monitor_key = '';

    /** One word or phrase per line, as the owner types them. Blocked senders are listed apart, not here. */
    public string $spam_words = '';

    /** What the last Unblock in Settings did, shown above the blocked senders. */
    public string $unblockNotice = '';

    public ?string $webhookTestResult = null;

    public string $recipient_email = '';

    public bool $notify_owner = true;

    public string $sender_name = '';

    public bool $recipients_can_view = false;

    public bool $recipients_weekly_summary = true;

    public ?string $recipientResult = null;

    public bool $showSpam = false;

    /** What the last Block sender or Not spam did, shown above the messages. */
    public string $blockNotice = '';

    public string $search = '';

    /** The form's name, typed again to confirm Delete form. */
    public string $deleteConfirm = '';

    public ?string $testResult = null;

    public ?string $retryResult = null;

    public ?int $selected = null;

    public function mount(Form $form): void
    {
        abort_unless($form->user_id === auth()->id(), 404);

        $this->form = $form;
        $this->name = $form->name;
        $this->site_url = (string) $form->site_url;
        $this->redirect_url = (string) $form->redirect_url;
        $this->retention_days = $form->retention_days;
        $this->store_submissions = $form->store_submissions;
        $this->webhook_url = (string) $form->webhook_url;
        $this->allowed_domains = implode("\n", $form->allowed_domains ?: []);
        $this->bot_check = $form->bot_check;
        $this->spam_words = self::ordinaryWords($form->spam_words);
        $this->notify_owner = $form->notify_owner;
        $this->sender_name = (string) $form->sender_name;
        $this->recipients_can_view = $form->recipients_can_view;
        $this->recipients_weekly_summary = $form->recipients_weekly_summary;
    }

    public function saveEmails(FormEmails $emails): void
    {
        $this->resetErrorBag(['notify_owner', 'sender_name', 'recipients_can_view', 'recipients_weekly_summary']);

        // Validation errors land on the properties' own names.
        $emails->update($this->form, ['notify_owner' => $this->notify_owner, 'sender_name' => $this->sender_name, 'recipients_can_view' => $this->recipients_can_view, 'recipients_weekly_summary' => $this->recipients_weekly_summary]);

        $this->dispatch('emails-saved');
    }

    public function addRecipient(FormEmails $emails): void
    {
        $this->resetErrorBag('recipient_email');

        try {
            $recipient = $emails->add($this->form, $this->recipient_email);
        } catch (ValidationException $e) {
            $this->addError('recipient_email', $e->validator->errors()->first('email'));

            return;
        }

        $this->recipientResult = 'We emailed '.$recipient->email.' a link to confirm. Nothing goes to them until they press it.';
        $this->recipient_email = '';
    }

    public function resendConfirmation(FormEmails $emails, int $id): void
    {
        $recipient = $this->form->recipients()->whereKey($id)->first();

        if (! $recipient) {
            return;
        }

        try {
            $emails->resend($recipient);
            $this->recipientResult = 'Sent the confirmation link to '.$recipient->email.' again.';
        } catch (ValidationException $e) {
            $this->recipientResult = $e->validator->errors()->first('email');
        }
    }

    public function removeRecipient(int $id): void
    {
        $this->form->recipients()->whereKey($id)->delete();
        $this->recipientResult = null;
    }

    public function saveSettings(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'site_url' => ['nullable', 'url:https,http', 'max:2048'],
            'redirect_url' => ['nullable', 'url:https,http', 'max:2048'],
            'retention_days' => ['required', 'integer', 'min:1', 'max:365'],
            'store_submissions' => ['boolean'],
            'webhook_url' => ['nullable', 'url:https', 'max:2048'],
            'allowed_domains' => ['nullable', 'string', 'max:5000'],
            'bot_check' => ['boolean'],
            'spam_words' => ['nullable', 'string', 'max:5000'],
            'monitor_key' => ['nullable', 'string', 'max:500'],
        ]);

        $settings = collect($validated)->only(array_keys(FormSettings::RULES))->all();
        // An empty box keeps the saved key: Remove is the way to clear it.
        if (blank($settings['monitor_key'] ?? null)) {
            unset($settings['monitor_key']);
        }
        $spam = collect($validated)->only(array_keys(FormSpamSettings::RULES))->all();

        // Both are checked before either is saved, so one bad field never leaves half a change behind.
        try {
            FormSettings::check($settings);
            // The blocked senders are not in the textarea, so they are added back and count towards the cap.
            $blocked = array_filter($this->form->fresh()->spam_words ?: [], BlockSender::isSender(...));
            $spamWords = FormSpamSettings::words([...FormSpamSettings::words($spam['spam_words']), ...$blocked]);
            $spam['spam_words'] = $spamWords;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }

        app(FormSettings::class)->update($this->form, $settings);
        app(FormSpamSettings::class)->update($this->form, $spam);

        // Shown back as stored, so the owner sees "www." and "https://" were dropped on purpose.
        $this->allowed_domains = implode("\n", $this->form->allowed_domains ?: []);
        $this->spam_words = self::ordinaryWords($spamWords);
        $this->monitor_key = '';

        $this->dispatch('settings-saved');
    }

    /** Remove on the DidMyFormSend key, through the same action REST and MCP use. */
    public function removeMonitorKey(): void
    {
        app(FormSettings::class)->update($this->form, ['monitor_key' => null]);
        $this->monitor_key = '';
        $this->resetErrorBag('monitor_key');
    }

    public function deleteSubmission(int $id): void
    {
        $this->form->submissions()->whereKey($id)->delete();
        $this->selected = null;
    }

    /** Spam that got through: the message moves to spam and its sender joins the spam words. */
    public function blockSender(int $id, BlockSender $block): void
    {
        $submission = $this->form->submissions()->findOrFail($id);
        $this->blockNotice = $block->block($submission, BlockSender::entryFor($submission->replyToAddress()));
        $this->form->refresh();
        $this->spam_words = self::ordinaryWords($this->form->spam_words);
        $this->selected = null;
    }

    /** The undo for a wrong Block, or for real mail the filters caught. */
    public function notSpam(int $id, BlockSender $block): void
    {
        $block->unblock($this->form->submissions()->findOrFail($id));
        $this->blockNotice = 'Moved back to the inbox.';
        $this->form->refresh();
        $this->spam_words = self::ordinaryWords($this->form->spam_words);
        $this->selected = null;
    }

    /** Unblock in the Settings list. The textarea is left alone, so unsaved typing there is kept. */
    public function unblockSender(string $entry, BlockSender $block): void
    {
        $this->unblockNotice = $block->unblockEntry($this->form, $entry);
    }

    /** The spam words the textarea shows: everything but the blocked senders. */
    private static function ordinaryWords(?array $words): string
    {
        return implode("\n", array_filter($words ?: [], fn (string $word) => ! BlockSender::isSender($word)));
    }

    public function selectSubmission(int $id): void
    {
        $this->selected = $id;
    }

    /** Sends a test event in exactly the shape of a real one, right now, and says what the endpoint answered. */
    public function sendTestWebhook(): void
    {
        $key = 'test-webhook:'.$this->form->id;

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->webhookTestResult = 'You sent 10 test events in the last hour. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes.';

            return;
        }
        RateLimiter::hit($key, 3600);

        if (! $this->form->webhook_url) {
            $this->webhookTestResult = 'Save a webhook address first.';

            return;
        }

        $sample = (new Submission([
            'data' => ['name' => 'FormHaven test', 'email' => 'visitor@example.com', 'message' => 'This is a test event from your FormHaven dashboard.'],
            'referer' => $this->form->site_url,
        ]))->setRelation('form', $this->form);
        $body = json_encode($sample->webhookPayload(test: true), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->webhookTestResult = $this->form->deliverWebhook($body)
            ? 'Test event delivered: your endpoint answered HTTP '.$this->form->webhook_last_status.'.'
            : 'Test event not delivered. '.$this->form->webhook_last_error;
    }

    /** Runs a sample message through the same path a visitor's post takes, so a pass means the form works. */
    public function sendTest(ReceiveSubmission $receive): void
    {
        $key = 'test-submission:'.$this->form->id;

        // Each test sends a real email; keep the shared daily mail quota safe.
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->testResult = 'You sent 3 tests in the last hour. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes, or submit the form on your site.';

            return;
        }
        RateLimiter::hit($key, 3600);

        $owner = $this->form->user;

        try {
            $submission = $receive->handle($this->form, [
                'name' => 'Test message',
                'message' => 'This is a test sent from the dashboard. If it reached your inbox, the form works.',
            ], ['ip' => request()->ip(), 'user_agent' => 'FormHaven dashboard test', 'dashboard_test' => true]);
        } catch (SubmissionRefused $e) {
            $this->testResult = $e->getMessage();

            return;
        }

        $this->showSpam = false;
        $this->resetPage();
        $to = collect($this->form->mailTargets())->map(fn (?FormRecipient $recipient) => $recipient?->email ?? $owner->email);
        $held = $submission->deliveryFailures()->first();

        $this->testResult = match (true) {
            $owner->emailsPaused() => 'Test saved below. No email was sent: emails are paused for this month.',
            $to->isEmpty() => 'Test saved below. No email was sent: verify your email address first.',
            $held !== null => 'Test saved below. No email was sent. '.$held->explain().' '.$held->fix(),
            default => 'Test sent. It is below, and an email is on its way to '.$to->join(', ', ' and ').'.',
        };
    }

    /** Deletes this form with its submissions after its name is typed again; the bill drops by one form. */
    public function deleteForm(DeleteForm $delete): void
    {
        $name = $this->form->name;

        try {
            $count = $delete->handle($this->form, $this->deleteConfirm);
        } catch (ValidationException $e) {
            $this->addError('deleteConfirm', $e->validator->errors()->first('confirm'));

            return;
        }

        session()->flash('status', DeleteForm::summary($name, $count, auth()->user()->isPaying()));
        $this->redirect(route('forms.index'), navigate: true);
    }

    /** Send again: the form's undelivered messages, through the same action REST and MCP use. */
    public function retryDeliveries(RetryDeliveries $retry): void
    {
        try {
            $this->retryResult = RetryDeliveries::summary($retry->handle($this->form));
        } catch (ValidationException $e) {
            $this->retryResult = $e->validator->errors()->first('retry');
        }
    }

    /** The setup keys of this form: the keys agents got from POST /api/v1/start, which reach this form only. */
    private function setupKeys()
    {
        $ability = \App\Models\User::FORM_ABILITY.$this->form->public_id;

        return auth()->user()->tokens()->latest()->get()
            ->filter(fn ($token) => in_array($ability, $token->abilities ?? [], true))
            ->values();
    }

    /** Revoke on a setup key: anything using it stops working at once. */
    public function revokeSetupKey(int $id): void
    {
        $this->setupKeys()->firstWhere('id', $id)?->delete();
    }

    public function updatedShowSpam(): void
    {
        $this->resetPage();
        $this->selected = null;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->selected = null;
    }

    public function with(): array
    {
        $term = trim($this->search);

        // Compared without case or accents on both sides, so "chloe" finds "Chloé".
        $submissions = $this->form->submissions()
            ->where('spam', $this->showSpam)
            ->when($term !== '', fn ($query) => $query->whereRaw(
                "search_text like ? escape '\\'",
                ['%'.addcslashes(\App\Models\Submission::searchable($term), '%_\\').'%'],
            ))
            ->latest()
            ->paginate(25);

        $inboxCount = $this->form->submissions()->where('spam', false)->count();
        // Loaded fresh on every render, so Send again and a resend show their effect straight away.
        $this->form->load(['recipients', 'deliveryFailures']);

        return [
            'submissions' => $submissions,
            // The open one, only from what the list shows now, so a search never leaves a stale one open.
            'current' => $submissions->firstWhere('id', $this->selected) ?? $submissions->first(),
            'searching' => $term,
            'inboxCount' => $inboxCount,
            'spamCount' => $this->form->submissions()->where('spam', true)->count(),
            'hasMessage' => $inboxCount > 0,
            'status' => $this->form->dashboardStatus(auth()->user()->coveredFormIds()),
            'snippets' => \App\Support\FormSnippets::for($this->form),
            'privacyTexts' => collect(\App\Support\PrivacyText::LANGUAGES)->mapWithKeys(fn ($l) => [$l => \App\Support\PrivacyText::for($this->form, $l)])->all(),
            'recipients' => $this->form->recipients,
            'health' => $this->form->health(),
            'monitor' => $this->form->monitorSummary(),
            'blockedSenders' => array_values(array_filter($this->form->spam_words ?: [], BlockSender::isSender(...))),
            // The latest undelivered email per recipient, for its line in the Emails tab.
            'recipientProblems' => $this->form->deliveryFailures->whereNotNull('form_recipient_id')->groupBy('form_recipient_id')->map(fn ($failures) => $failures->last()->explain()),
            // Usually one key per form. The prefix lives on the reservation; keys made before it was kept have none.
            'setupKeys' => $this->setupKeys()->each(fn ($token) => $token->setAttribute('prefix',
                \App\Models\FormClaim::where('setup_key_hash', $token->token)->value('setup_key_prefix'))),
        ];
    }
}; ?>
<div class="flex w-full min-w-0 flex-col gap-5" x-data="{ tab: ['submissions', 'setup', 'emails', 'settings', 'webhook', 'privacy'].includes(location.hash.slice(1)) ? location.hash.slice(1) : @js($hasMessage ? 'submissions' : 'setup') }">
    @if (session('status'))
        <p class="rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-900 dark:border-green-800 dark:bg-green-950 dark:text-green-100" role="status">{{ session('status') }}</p>
    @endif
    <div>
        <p class="text-sm text-zinc-500"><a href="{{ route('forms.index') }}" class="text-zinc-600 hover:underline dark:text-zinc-400" wire:navigate>Forms</a> / {{ $form->name }}</p>
        <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex flex-wrap items-center gap-2.5">
                    <flux:heading size="xl" level="1">{{ $form->name }}</flux:heading>
                    <x-app.status-badge :status="$status" />
                </div>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    {{ $form->siteHost() ?? 'No website set' }}
                    · {{ $form->store_submissions ? 'keeps submissions for '.$form->retention_days.' '.Str::plural('day', $form->retention_days) : 'keeps no submissions' }}
                    @if ($form->webhook_url) · webhook on @endif
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($hasMessage)
                    <flux:button icon="arrow-down-tray" :href="route('forms.export', $form)">Export CSV</flux:button>
                @endif
                <flux:button variant="primary" icon="paper-airplane" wire:click="sendTest">Send a test submission</flux:button>
            </div>
        </div>
    </div>

    <x-plan-status />

    @if ($testResult)
        <p class="rounded-xl border border-zinc-200 bg-white px-4 py-3 text-sm dark:border-zinc-700 dark:bg-zinc-900" role="status">{{ $testResult }}</p>
    @endif

    @if ($health['problems'] !== [])
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100" role="status" data-health>
            <p class="font-semibold">{{ count($health['problems']) === 1 ? 'One thing needs a look' : count($health['problems']).' things need a look' }}</p>
            <ul class="mt-2 flex flex-col gap-2">
                @foreach ($health['problems'] as $problem)
                    <li>{{ $problem['message'] }} <span class="block">{{ $problem['fix'] }}</span></li>
                @endforeach
            </ul>
            @if (collect($health['problems'])->contains('code', 'UNDELIVERED'))
                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <flux:button size="sm" icon="paper-airplane" wire:click="retryDeliveries">Send again</flux:button>
                    <span class="text-amber-800 dark:text-amber-200">The messages are saved below, so nothing is lost.</span>
                </div>
            @endif
            @if ($retryResult)
                <p class="mt-3" role="status">{{ $retryResult }}</p>
            @endif
        </div>
    @elseif ($retryResult)
        <p class="rounded-xl border border-zinc-200 bg-white px-4 py-3 text-sm dark:border-zinc-700 dark:bg-zinc-900" role="status">{{ $retryResult }}</p>
    @endif

    <div class="flex flex-wrap items-center gap-3 rounded-xl border border-zinc-200 bg-white py-2.5 pr-3 pl-4 dark:border-zinc-700 dark:bg-zinc-900" x-data="{ copied: false }">
        <span class="text-xs font-semibold text-zinc-600 dark:text-zinc-400">Form address</span>
        <span class="min-w-[14rem] flex-1 font-mono text-sm break-all">{{ $form->endpoint() }}</span>
        <flux:button size="sm" icon="clipboard" x-on:click="navigator.clipboard.writeText({{ Js::from($form->endpoint()) }}); copied = true; setTimeout(() => copied = false, 2000)">
            <span x-text="copied ? 'Copied' : 'Copy address'">Copy address</span>
        </flux:button>
    </div>

    <nav class="flex gap-1 overflow-x-auto border-b border-zinc-200 whitespace-nowrap dark:border-zinc-700" aria-label="Form sections">
        @foreach (['submissions' => 'Submissions', 'setup' => 'Setup', 'emails' => 'Emails', 'settings' => 'Settings', 'webhook' => 'Webhook', 'privacy' => 'Privacy text'] as $key => $label)
            <a href="#{{ $key }}" x-on:click.prevent="tab = '{{ $key }}'; history.replaceState(null, '', '#{{ $key }}')"
                class="-mb-px flex min-h-10 items-center gap-2 border-b-2 px-3 text-sm font-medium"
                x-bind:class="tab === '{{ $key }}' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white'"
                x-bind:aria-current="tab === '{{ $key }}' ? 'page' : null">
                {{ $label }}
                @if ($key === 'submissions')<span class="rounded-full bg-zinc-100 px-1.5 text-xs text-zinc-600 tabular-nums dark:bg-zinc-800 dark:text-zinc-400">{{ number_format($inboxCount) }}</span>@endif
                @if ($key === 'webhook' && $form->webhook_url)<span class="size-1.5 rounded-full {{ $form->webhook_last_error ? 'bg-red-600' : 'bg-green-600' }}" aria-hidden="true"></span>@endif
            </a>
        @endforeach
    </nav>

    {{-- Submissions --}}
    <section class="flex flex-col gap-4" x-show="tab === 'submissions'" @unless ($hasMessage) x-cloak @endunless>
        <div class="flex flex-wrap items-center gap-3">
            <div class="inline-flex rounded-lg bg-zinc-100 p-[3px] dark:bg-zinc-800" role="group" aria-label="Show">
                <button type="button" wire:click="$set('showSpam', false)" aria-pressed="{{ $showSpam ? 'false' : 'true' }}" @class(['min-h-8 rounded-md px-3 text-sm font-medium', 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white' => ! $showSpam, 'text-zinc-600 dark:text-zinc-400' => $showSpam])>Inbox <span class="text-zinc-500">{{ number_format($inboxCount) }}</span></button>
                <button type="button" wire:click="$set('showSpam', true)" aria-pressed="{{ $showSpam ? 'true' : 'false' }}" @class(['min-h-8 rounded-md px-3 text-sm font-medium', 'bg-white text-zinc-900 shadow-xs dark:bg-zinc-700 dark:text-white' => $showSpam, 'text-zinc-600 dark:text-zinc-400' => ! $showSpam])>Spam <span class="text-zinc-500">{{ number_format($spamCount) }}</span></button>
            </div>
            <div class="w-full sm:w-72">
                <flux:input wire:model.live.debounce.300ms="search" type="search" size="sm" icon="magnifying-glass" placeholder="Search answers" aria-label="Search answers" />
            </div>
            <div class="flex-1"></div>
            @if ($submissions->total() > 0)
                <span class="text-sm text-zinc-500">{{ number_format($submissions->firstItem()) }} to {{ number_format($submissions->lastItem()) }} of {{ number_format($submissions->total()) }}</span>
            @endif
        </div>

        @if ($blockNotice !== '')
            <p class="rounded-lg bg-zinc-100 px-3 py-2 text-sm text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300" role="status">{{ $blockNotice }}</p>
        @endif

        @if ($current)
            <div class="flex flex-wrap overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                <div class="min-w-0 flex-[1_1_320px] border-zinc-200 max-lg:border-b lg:border-r dark:border-zinc-700">
                    @foreach ($submissions as $submission)
                        @php [$who, $preview] = $submission->summary(); @endphp
                        <button type="button" wire:key="submission-{{ $submission->id }}" wire:click="selectSubmission({{ $submission->id }})"
                            @class(['block w-full border-b border-zinc-100 px-4 py-3 text-left last:border-b-0 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800/50', 'bg-zinc-100 shadow-[inset_3px_0_0_var(--color-zinc-900)] dark:bg-zinc-800 dark:shadow-[inset_3px_0_0_white]' => $submission->is($current)])>
                            <span class="flex justify-between gap-3">
                                <span class="truncate font-semibold">{{ $who }}</span>
                                <span class="flex-none text-xs whitespace-nowrap text-zinc-500">{{ $submission->created_at->diffForHumans(short: true) }}</span>
                            </span>
                            @if ($preview !== '')
                                <span class="mt-0.5 block truncate text-sm text-zinc-600 dark:text-zinc-400">{{ $preview }}</span>
                            @endif
                        </button>
                    @endforeach
                    @if ($submissions->hasPages())
                        <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">{{ $submissions->links() }}</div>
                    @endif
                </div>

                <article class="min-w-0 flex-[999_1_420px] px-6 py-5" wire:key="detail-{{ $current->id }}">
                    @php [$who] = $current->summary(); @endphp
                    <div class="flex flex-wrap justify-between gap-3 border-b border-zinc-200 pb-4 dark:border-zinc-700">
                        <div class="min-w-0">
                            <flux:heading size="lg" level="2" class="break-words">{{ $who }}</flux:heading>
                            <p class="mt-1 text-sm text-zinc-500">{{ $current->created_at->toDayDateTimeString() }}@if ($current->sourcePage()) · sent from {{ $current->sourcePage() }}@endif</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            @if ($current->replyToAddress())
                                <flux:button size="sm" icon="arrow-uturn-left" href="mailto:{{ $current->replyToAddress() }}">Reply</flux:button>
                            @endif
                            @if ($current->spam)
                                <flux:button size="sm" wire:click="notSpam({{ $current->id }})">Not spam</flux:button>
                            @else
                                @php($blockEntry = \App\Actions\BlockSender::entryFor($current->replyToAddress()))
                                <flux:button size="sm" variant="ghost" wire:click="blockSender({{ $current->id }})" wire:confirm="{{ $blockEntry ? 'Move this to spam and send new messages from '.$blockEntry.' to spam too?' : 'Move this to spam?' }}">{{ $blockEntry ? 'Spam: block sender' : 'Move to spam' }}</flux:button>
                            @endif
                            <flux:button size="sm" variant="ghost" wire:click="deleteSubmission({{ $current->id }})" wire:confirm="Delete this submission for good?">Delete</flux:button>
                        </div>
                    </div>
                    @foreach ($current->deliveryFailures as $failure)
                        <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100">Not delivered to {{ $failure->email }}. {{ $failure->explain() }}</p>
                    @endforeach
                    @if ($current->spamReason())
                        <p class="mt-4 rounded-lg bg-zinc-100 px-3 py-2 text-sm text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">Marked as spam. {{ $current->spamReason() }}</p>
                    @endif
                    @if ($current->isForgotten())
                        <p class="pt-4 text-sm text-zinc-600 dark:text-zinc-400">{{ $current->summary()[1] }}</p>
                    @else
                        <dl class="grid gap-x-6 text-sm sm:grid-cols-[minmax(110px,160px)_minmax(0,1fr)]">
                            @foreach ($current->data as $field => $answer)
                                <dt class="border-zinc-100 pt-3 font-semibold break-words text-zinc-600 sm:border-b sm:pb-3 dark:border-zinc-800 dark:text-zinc-400">{{ $field }}</dt>
                                <dd class="border-b border-zinc-100 pt-1 pb-3 break-words whitespace-pre-line sm:pt-3 dark:border-zinc-800">{{ is_array($answer) ? implode(', ', $answer) : $answer }}</dd>
                            @endforeach
                        </dl>
                    @endif
                </article>
            </div>
        @else
            <p class="rounded-xl border border-dashed border-zinc-300 bg-white px-4 py-8 text-center text-zinc-500 dark:border-zinc-700 dark:bg-zinc-900">{{ match (true) {
                $searching !== '' => 'Nothing '.($showSpam ? 'in spam ' : '').'matches "'.$searching.'".',
                $showSpam => 'No spam caught.',
                default => 'No submissions yet. Press Send a test submission to see one arrive here and in your inbox, or submit the form on your site.',
            } }}</p>
        @endif
    </section>

    {{-- Setup --}}
    <section class="flex flex-col gap-5" x-show="tab === 'setup'" @if ($hasMessage) x-cloak @endif>
        <ol aria-label="Setup steps" class="grid overflow-hidden rounded-xl border border-zinc-200 bg-white sm:grid-cols-3 dark:border-zinc-700 dark:bg-zinc-900">
            <li class="flex gap-3 p-5 max-sm:border-b sm:border-r border-zinc-100 dark:border-zinc-800">
                <span class="grid size-7 flex-none place-items-center rounded-full bg-zinc-900 text-sm text-white dark:bg-white dark:text-zinc-900" aria-hidden="true">&check;</span>
                <div><p class="font-semibold">Form created <span class="sr-only">(done)</span></p><p class="text-sm text-zinc-600 dark:text-zinc-400">Its address is above.</p></div>
            </li>
            <li class="flex gap-3 p-5 max-sm:border-b sm:border-r border-zinc-100 dark:border-zinc-800">
                <span class="grid size-7 flex-none place-items-center rounded-full border-2 border-zinc-900 text-sm font-semibold dark:border-white" aria-hidden="true">2</span>
                <div><p class="font-semibold">Add it to your site</p><p class="text-sm text-zinc-600 dark:text-zinc-400">Paste the code for your stack.</p></div>
            </li>
            <li class="flex gap-3 p-5">
                @if ($hasMessage)
                    <span class="grid size-7 flex-none place-items-center rounded-full bg-zinc-900 text-sm text-white dark:bg-white dark:text-zinc-900" aria-hidden="true">&check;</span>
                    <div><p class="font-semibold">First message received <span class="sr-only">(done)</span></p><p class="text-sm text-zinc-600 dark:text-zinc-400">Now try it from your site.</p></div>
                @else
                    <span class="grid size-7 flex-none place-items-center rounded-full border-2 border-zinc-300 text-sm font-semibold text-zinc-600 dark:border-zinc-600 dark:text-zinc-400" aria-hidden="true">3</span>
                    <div><p class="font-semibold">First message received</p><p class="text-sm text-zinc-600 dark:text-zinc-400">Waiting for the first message.</p></div>
                @endif
            </li>
        </ol>

        <div class="flex flex-wrap items-start gap-5">
            <div class="min-w-0 flex-[999_1_520px] overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900" x-data="{ stack: 'html' }">
                <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700"><flux:heading size="md" level="2">Add it to your site</flux:heading></div>
                <div role="tablist" aria-label="Your stack" class="flex flex-wrap gap-1 border-b border-zinc-200 bg-zinc-50 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800/50">
                    @foreach ($snippets as $id => [$label])
                        <button type="button" role="tab" x-on:click="stack = '{{ $id }}'" x-bind:aria-selected="stack === '{{ $id }}'" x-bind:class="stack === '{{ $id }}' ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'text-zinc-600 dark:text-zinc-400'" class="min-h-8 rounded-md px-3 text-sm font-medium">{{ $label }}</button>
                    @endforeach
                </div>
                @foreach ($snippets as $id => [$label, $code, $hint])
                    <div role="tabpanel" x-show="stack === '{{ $id }}'" @if ($id !== 'html') style="display: none" @endif>
                        <pre class="overflow-x-auto bg-zinc-900 p-5 text-sm leading-relaxed text-zinc-100"><code>{{ $code }}</code></pre>
                        <div class="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                            <p class="min-w-0 flex-[1_1_280px] text-sm text-zinc-600 dark:text-zinc-400">{{ $hint }}</p>
                            <flux:button size="sm" icon="clipboard" x-on:click="navigator.clipboard.writeText({{ Js::from($code) }}); $el.querySelector('span').textContent = 'Copied'; setTimeout(() => $el.querySelector('span').textContent = 'Copy code', 2000)"><span>Copy code</span></flux:button>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="flex min-w-0 flex-[1_1_280px] flex-col gap-4">
                <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:heading size="md" level="2">Check it works</flux:heading>
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">A test runs through the same path a visitor's message takes. It shows up under Submissions and lands in your inbox.</p>
                    <flux:button variant="primary" icon="paper-airplane" wire:click="sendTest">Send a test submission</flux:button>
                </div>
                <div class="flex flex-col gap-2.5 rounded-xl border border-zinc-200 bg-white p-5 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:heading size="md" level="2">Already on</flux:heading>
                    <p class="flex gap-2"><flux:icon.check class="mt-0.5 size-4 flex-none text-green-700 dark:text-green-400" />{{ $form->bot_check ? 'Spam filter, the _gotcha field and the bot check' : 'Spam filter, with the hidden _gotcha field and a link filter' }}</p>
                    <p class="flex gap-2"><flux:icon.check class="mt-0.5 size-4 flex-none text-green-700 dark:text-green-400" />{{ $recipients->whereNotNull('confirmed_at')->isEmpty() ? 'An email to you for every message' : 'An email for every message, to you and your client' }}</p>
                    <p class="flex gap-2"><flux:icon.check class="mt-0.5 size-4 flex-none text-green-700 dark:text-green-400" />{{ $form->store_submissions ? 'Submissions stored'.(\App\Support\Operator::storedInEu() ? ' in the EU' : '').' for '.$form->retention_days.' '.Str::plural('day', $form->retention_days) : 'Answers deleted once emailed' }}</p>
                    <a href="#settings" x-on:click.prevent="tab = 'settings'; history.replaceState(null, '', '#settings')" class="font-medium underline">Change these in Settings</a>
                </div>
            </div>
        </div>
    </section>

    {{-- Emails --}}
    <section class="flex flex-col gap-5" x-show="tab === 'emails'" x-cloak>
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-wrap gap-6 p-6">
                <div class="flex-[1_1_240px] lg:max-w-xs"><flux:heading size="md" level="2">Send to your client</flux:heading><p class="mt-1.5 text-sm text-zinc-600 dark:text-zinc-400">Each message also goes straight to these addresses, under your name, so you stop forwarding leads by hand. Up to {{ FormRecipient::MAX_PER_FORM }} per form.</p></div>
                <div class="flex max-w-2xl min-w-0 flex-[999_1_420px] flex-col gap-4">
                    @if ($recipients->isNotEmpty())
                        <ul class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                            @foreach ($recipients as $recipient)
                                <li class="flex flex-wrap items-center gap-3 px-4 py-3 text-sm" wire:key="recipient-{{ $recipient->id }}">
                                    <span class="min-w-0 flex-[1_1_12rem] break-all">{{ $recipient->email }}@if ($recipientProblems->has($recipient->id))<span class="block text-amber-800 dark:text-amber-200">Not delivered. {{ $recipientProblems[$recipient->id] }}</span>@endif</span>
                                    <span class="flex flex-wrap items-center gap-1">
                                        @if ($recipient->isConfirmed())
                                            <span class="mr-2 inline-flex h-[22px] items-center rounded-full bg-green-50 px-2 text-xs font-medium text-green-800 dark:bg-green-950 dark:text-green-200">Getting messages</span>
                                            @if ($recipient->summary_stopped_at && $form->recipients_can_view && $form->recipients_weekly_summary)
                                                <span class="mr-2 text-xs text-zinc-600 dark:text-zinc-400">Stopped the weekly count</span>
                                            @endif
                                            @if ($form->recipients_can_view)
                                                <flux:button size="sm" variant="ghost" x-on:click="navigator.clipboard.writeText({{ Js::from($recipient->messagesUrl()) }}); $el.querySelector('span').textContent = 'Copied'; setTimeout(() => $el.querySelector('span').textContent = 'Copy page link', 2000)"><span>Copy page link</span></flux:button>
                                            @endif
                                        @else
                                            <span class="mr-2 inline-flex h-[22px] items-center rounded-full bg-amber-50 px-2 text-xs font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-200">Waiting for confirmation</span>
                                            <flux:button size="sm" variant="ghost" wire:click="resendConfirmation({{ $recipient->id }})">Resend link</flux:button>
                                        @endif
                                        <flux:button size="sm" variant="ghost" wire:click="removeRecipient({{ $recipient->id }})" wire:confirm="Stop emailing {{ $recipient->email }}?">Remove</flux:button>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <form wire:submit="addRecipient" class="flex flex-wrap items-start gap-2">
                        <div class="min-w-0 flex-[1_1_240px]"><flux:input wire:model="recipient_email" type="email" placeholder="client@example.nl" aria-label="Email address to add" /></div>
                        <flux:button type="submit">Add address</flux:button>
                    </form>
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">We email each new address a link to confirm, and nothing goes to it until they press it. Every email they get has a link to stop them.</p>
                    @if ($recipientResult)
                        <p class="text-sm" role="status">{{ $recipientResult }}</p>
                    @endif
                </div>
            </div>
        </div>

        <form wire:submit="saveEmails" class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-wrap gap-6 p-6">
                <div class="flex-[1_1_240px] lg:max-w-xs"><flux:heading size="md" level="2">How they see it</flux:heading><p class="mt-1.5 text-sm text-zinc-600 dark:text-zinc-400">Your client's copy shows your name as the sender and carries no FormHaven branding. It is sent from our address, so it passes spam checks.</p></div>
                <div class="flex max-w-2xl min-w-0 flex-[999_1_420px] flex-col gap-5">
                    <flux:input wire:model="sender_name" label="Sender name" :placeholder="$form->user->name" description="Shown as who the email is from, for example your agency's name. Empty uses your account name." maxlength="60" />
                    <flux:switch wire:model="notify_owner" label="Email me too" description="Off: only the confirmed addresses above get messages. Until one of them has confirmed, they still come to you, so nothing is missed." />
                    <flux:switch wire:model="recipients_can_view" label="Let them read messages on a page" description="Each email to a confirmed address links to a page with this form's stored messages and how many came in this month and last month. Anyone the email is forwarded to can open it too. Off again closes the page at once." />
                    <flux:switch wire:model="recipients_weekly_summary" label="Send them a weekly count" description="Needs the page above. Once a week, a confirmed address gets a short email with last week's number of messages, the link to that page and a line asking them to check their spam folder if they missed any. Only sent in a week with messages. Each address can stop it, and keeps getting the messages." />
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-4 border-t border-zinc-200 bg-zinc-50 px-6 py-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                <x-action-message on="emails-saved">Saved.</x-action-message>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </section>

    {{-- Settings --}}
    <section x-show="tab === 'settings'" x-cloak>
        <form wire:submit="saveSettings" class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-wrap gap-6 p-6">
                <div class="flex-[1_1_240px] lg:max-w-xs"><flux:heading size="md" level="2">General</flux:heading><p class="mt-1.5 text-sm text-zinc-600 dark:text-zinc-400">How this form shows up in your dashboard and in the emails we send you.</p></div>
                <div class="flex max-w-2xl min-w-0 flex-[999_1_420px] flex-col gap-5">
                    <flux:input wire:model="name" label="Name" required />
                    <flux:input wire:model="site_url" type="url" label="Website (optional)" placeholder="https://example.nl" description="The site this form is on. Forms are grouped by it, and our thank-you page shows visitors a button back to it." />
                </div>
            </div>
            <div class="flex flex-wrap gap-6 border-t border-zinc-200 p-6 dark:border-zinc-700">
                <div class="flex-[1_1_240px] lg:max-w-xs"><flux:heading size="md" level="2">After sending</flux:heading><p class="mt-1.5 text-sm text-zinc-600 dark:text-zinc-400">What a visitor sees once their message is in.</p></div>
                <div class="flex max-w-2xl min-w-0 flex-[999_1_420px] flex-col gap-5">
                    <flux:input wire:model="redirect_url" type="url" label="Thank-you page (optional)" placeholder="https://example.nl/thanks" description="Where visitors go after sending. Leave empty to show our standard thank-you page." />
                </div>
            </div>
            <div class="flex flex-wrap gap-6 border-t border-zinc-200 p-6 dark:border-zinc-700">
                <div class="flex-[1_1_240px] lg:max-w-xs"><flux:heading size="md" level="2">Allowed sites</flux:heading><p class="mt-1.5 text-sm text-zinc-600 dark:text-zinc-400">Your form's address sits in your page's HTML. List your sites here and a copy of it on any other site goes to spam instead of your inbox.</p></div>
                <div class="flex max-w-2xl min-w-0 flex-[999_1_420px] flex-col gap-3">
                    <flux:textarea wire:model="allowed_domains" label="Domains (optional)" rows="3" :placeholder="$form->siteHost() ?? 'example.nl'" description="One per line. A domain covers its subdomains, so example.nl also accepts www.example.nl and shop.example.nl. Localhost always works, for testing. Empty: any site can send to this form." />
                    @if ($form->siteHost())
                        <div x-show="! $wire.allowed_domains.trim()"><flux:button size="sm" variant="ghost" icon="plus" x-on:click="$wire.allowed_domains = {{ \Illuminate\Support\Js::from($form->siteHost()) }}">Add {{ $form->siteHost() }}</flux:button></div>
                    @endif
                </div>
            </div>
            <div class="flex flex-wrap gap-6 border-t border-zinc-200 p-6 dark:border-zinc-700">
                <div class="flex-[1_1_240px] lg:max-w-xs"><flux:heading size="md" level="2">Spam protection</flux:heading><p class="mt-1.5 text-sm text-zinc-600 dark:text-zinc-400">Always on: the hidden trap field, a rate limit, and a filter for messages stuffed with links. Spam is never emailed and never counts against your limit; you can read it under Submissions.</p></div>
                <div class="flex max-w-2xl min-w-0 flex-[999_1_420px] flex-col gap-5">
                    <div class="flex flex-col gap-3">
                        <flux:switch wire:model.live="bot_check" label="Bot check" description="Your visitors' browsers solve a small puzzle in the background before the form sends. They see nothing and click nothing, and there are no cookies. Bots that do not run a real browser fail it, and their posts go to spam. A visitor whose browser has JavaScript turned off fails it too, so check spam now and then." />
                        <div x-show="$wire.bot_check" x-cloak class="flex flex-col gap-2 rounded-lg border border-zinc-200 bg-zinc-50 p-4 text-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                            <p class="font-medium">Add this line once to every page with the form, then save:</p>
                            <pre class="overflow-x-auto rounded-md bg-zinc-900 px-3 py-2 text-zinc-100"><code>{{ Form::botCheckScript() }}</code></pre>
                            <p class="text-zinc-600 dark:text-zinc-400">Without it, every post goes to spam. The code under Setup includes it once this is saved.</p>
                        </div>
                    </div>
                    <flux:textarea wire:model="spam_words" label="Spam words (optional)" rows="3" placeholder="seo services&#10;crypto" description="One word or phrase per line. A message containing one goes to spam. Capitals and accents do not matter, and a word also matches inside a longer one. Up to {{ FormSpamSettings::MAX_WORDS }}, blocked senders below included. A line with @ in it, such as @company.com, becomes a blocked sender when you save." />
                    <div class="flex flex-col gap-3" data-blocked-senders x-data="{ q: '' }">
                        <div>
                            <flux:heading size="sm" level="3">Blocked senders</flux:heading>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">New messages from these go straight to spam. A company is blocked by its domain, an address at Gmail or another shared provider on its own.</p>
                        </div>
                        @if ($unblockNotice !== '')
                            <p class="rounded-lg bg-zinc-100 px-3 py-2 text-sm text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300" role="status">{{ $unblockNotice }}</p>
                        @endif
                        @if ($blockedSenders === [])
                            <p class="text-sm text-zinc-600 dark:text-zinc-400">To block one, press "Spam? Block this sender" in the email about a message, or "Spam: block sender" on a message under Submissions.</p>
                        @else
                            @if (count($blockedSenders) > 10)
                                <div class="sm:max-w-72"><flux:input x-model="q" type="search" size="sm" icon="magnifying-glass" placeholder="Filter blocked senders" aria-label="Filter blocked senders" /></div>
                            @endif
                            <ul class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                                @foreach ($blockedSenders as $entry)
                                    {{-- x-bind:hidden, not x-show: inside the tab's own x-show, rows hidden by x-show got stuck hidden (Alpine 3.17). The filter only counts while its box is shown. --}}
                                    <li wire:key="blocked-{{ md5($entry) }}" x-bind:hidden="{{ count($blockedSenders) > 10 ? 'true' : 'false' }} && q.trim() !== '' && ! {{ Js::from($entry) }}.includes(q.trim().toLowerCase())" class="flex items-center justify-between gap-3 py-1.5 pr-1.5 pl-3">
                                        <span class="min-w-0 font-mono text-sm break-all">{{ $entry }}</span>
                                        <flux:button size="sm" variant="ghost" wire:click="unblockSender({{ Js::from($entry) }})">Unblock</flux:button>
                                    </li>
                                @endforeach
                            </ul>
                            <p hidden x-bind:hidden="{{ count($blockedSenders) > 10 ? 'false' : 'true' }} || ! q.trim() || {{ Js::from($blockedSenders) }}.some(e => e.includes(q.trim().toLowerCase()))" class="text-sm text-zinc-600 dark:text-zinc-400">No blocked sender matches that.</p>
                        @endif
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap gap-6 border-t border-zinc-200 p-6 dark:border-zinc-700">
                <div class="flex-[1_1_240px] lg:max-w-xs"><flux:heading size="md" level="2">Data and privacy</flux:heading><p class="mt-1.5 text-sm text-zinc-600 dark:text-zinc-400">{{ \App\Support\Operator::storedInEu() ? 'Submissions are stored in the EU. ' : '' }}Keep them only as long as your client needs.</p></div>
                <div class="flex max-w-2xl min-w-0 flex-[999_1_420px] flex-col gap-5">
                    <flux:switch wire:model.live="store_submissions" label="Keep submissions in FormHaven" description="Off: each message is emailed to you and its answers are then deleted, so nothing your visitors sent stays with us. You lose search and export for this form. If an email cannot go out ({{ \App\Support\Mode::hosted() ? 'unverified address, daily or monthly email limit reached' : 'unverified address, daily email limit reached' }}), the message is kept so it is not lost, for the days set below. So is a message from a site that is not on your allowed list, in spam, where you can check it." />
                    <flux:input wire:model="retention_days" type="number" min="1" max="365" label="Keep submissions for (days)" description="Older submissions are deleted automatically every night." required class="max-w-40" />
                </div>
            </div>
            <div class="flex flex-wrap gap-6 border-t border-zinc-200 p-6 dark:border-zinc-700" data-monitor-key>
                <div class="flex-[1_1_240px] lg:max-w-xs"><flux:heading size="md" level="2">Delivery monitor</flux:heading><p class="mt-1.5 text-sm text-zinc-600 dark:text-zinc-400">Get an alert when this form's emails stop going out, from @if (\App\Support\Mode::hosted())<a href="https://didmyformsend.com" class="underline" target="_blank" rel="noopener">DidMyFormSend</a>@else DidMyFormSend (didmyformsend.com)@endif, a separate monitor.</p></div>
                <div class="flex max-w-2xl min-w-0 flex-[999_1_420px] flex-col gap-3">
                    @if ($monitor['on'])
                        <div>
                            <flux:heading size="sm" level="3">DidMyFormSend key</flux:heading>
                            <div class="mt-2 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-zinc-200 py-1.5 pr-1.5 pl-3 dark:border-zinc-700">
                                <span class="min-w-0 font-mono text-sm break-all">{{ $monitor['key_prefix'] ? $monitor['key_prefix'].'…' : 'fmh_…' }}</span>
                                <flux:button size="sm" variant="ghost" wire:click="removeMonitorKey" wire:confirm="Remove the DidMyFormSend key? This form stops reporting to the monitor.">Remove</flux:button>
                            </div>
                            @if ($form->monitor_refused_at)
                                <p class="mt-2 text-sm text-amber-800 dark:text-amber-200">DidMyFormSend refused this key {{ $form->monitor_refused_at->diffForHumans() }}. Remove it and paste a current key from the monitor's page.</p>
                            @endif
                        </div>
                    @else
                        <flux:input wire:model="monitor_key" label="DidMyFormSend key (optional)" placeholder="fmh_..." autocomplete="off" spellcheck="false" />
                    @endif
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">With a key saved, after each message we tell didmyformsend.com whether its emails went out, the mail service's name, the time, and your notification addresses as their first letter and domain plus a hash. Nothing a visitor wrote is sent. Remove the key to stop.</p>
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-4 border-t border-zinc-200 bg-zinc-50 px-6 py-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                <x-action-message on="settings-saved">Saved.</x-action-message>
                <flux:button type="submit" variant="primary">Save changes</flux:button>
            </div>
        </form>

        @if ($setupKeys->isNotEmpty())
            <div class="mt-6 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900" data-setup-keys>
                <div class="flex flex-wrap gap-6 p-6">
                    <div class="flex-[1_1_240px] lg:max-w-xs"><flux:heading size="md" level="2">Agent setup keys</flux:heading><p class="mt-1.5 text-sm text-zinc-600 dark:text-zinc-400">The key your agent got when it set up this form. It reaches this form only and works for {{ \App\Actions\StartForm::setupKeyDays() }} days after you activated it. After that, create a key on the <a href="{{ route('api-keys') }}" class="underline" wire:navigate>API keys</a> page.</p></div>
                    <ul class="flex max-w-2xl min-w-0 flex-[999_1_420px] flex-col divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                        @foreach ($setupKeys as $key)
                            <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm" wire:key="setup-key-{{ $key->id }}">
                                <div class="min-w-0">
                                    <p class="font-mono">{{ $key->prefix ? $key->prefix.'…' : 'fhs_…' }}</p>
                                    <p class="text-zinc-500">Created {{ $key->created_at->toFormattedDateString() }} · {{ match (true) {
                                        $key->expires_at === null => 'does not expire',
                                        $key->expires_at->isPast() => 'expired '.$key->expires_at->toFormattedDateString(),
                                        default => 'expires '.$key->expires_at->toFormattedDateString(),
                                    } }} · {{ $key->last_used_at ? 'last used '.$key->last_used_at->diffForHumans() : 'never used' }}</p>
                                </div>
                                <flux:button size="sm" variant="ghost" wire:click="revokeSetupKey({{ $key->id }})" wire:confirm="Revoke this key? Anything using it stops working.">Revoke</flux:button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        <div class="mt-6 flex flex-wrap items-center justify-between gap-4 rounded-xl border border-red-200 bg-white p-6 dark:border-red-900 dark:bg-zinc-900">
            <div class="max-w-2xl">
                <flux:heading size="lg" level="2">Delete this form</flux:heading>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">For a client site that is gone. Its address stops accepting messages, its {{ number_format($this->form->submissions()->count()) }} stored submissions are deleted{{ auth()->user()->isPaying() ? ', and your bill counts one form fewer from today' : '' }}. This cannot be undone.</p>
            </div>
            <flux:modal.trigger name="confirm-form-deletion">
                <flux:button variant="danger">Delete form</flux:button>
            </flux:modal.trigger>
        </div>

        <flux:modal name="confirm-form-deletion" :show="$errors->has('deleteConfirm')" focusable class="max-w-lg">
            <form wire:submit="deleteForm" class="space-y-6">
                <div>
                    <flux:heading size="lg">Delete {{ $form->name }}?</flux:heading>
                    <flux:subheading>Its submissions are deleted with it and cannot be brought back. Type the form's name to confirm.</flux:subheading>
                </div>
                <flux:input wire:model="deleteConfirm" :label="'Form name: '.$form->name" autocomplete="off" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="filled">Cancel</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="danger">Delete form</flux:button>
                </div>
            </form>
        </flux:modal>
    </section>

    {{-- Webhook --}}
    <section class="flex flex-col gap-5" x-show="tab === 'webhook'" x-cloak>
        <form wire:submit="saveSettings" class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-wrap gap-6 p-6">
                <div class="flex-[1_1_240px] lg:max-w-xs"><flux:heading size="md" level="2">Webhook</flux:heading><p class="mt-1.5 text-sm text-zinc-600 dark:text-zinc-400">Send every submission on to Slack, Zapier, Make, n8n or your own code.</p></div>
                <div class="flex max-w-2xl min-w-0 flex-[999_1_420px] flex-col gap-5">
                    <flux:input wire:model="webhook_url" type="url" label="Webhook (optional)" placeholder="https://example.com/hooks/formhaven" description="Each submission is also sent here as JSON in a POST, signed, within seconds. Spam is not sent. A failed delivery is retried 4 times over about 37 minutes. A Slack incoming webhook address gets a readable message instead." />
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-4 border-t border-zinc-200 bg-zinc-50 px-6 py-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                <x-action-message on="settings-saved">Saved.</x-action-message>
                <flux:button type="submit" variant="primary">Save webhook</flux:button>
            </div>
        </form>

        @if ($form->webhook_url)
            <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                <p class="text-sm break-all">Sending to <span class="font-mono">{{ $form->webhook_url }}</span></p>
                <div class="flex flex-wrap items-center gap-3 rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                    @if ($form->webhook_last_at)
                        <span @class(['inline-flex h-[22px] items-center rounded-full px-2 text-xs font-medium', 'bg-red-50 text-red-800 dark:bg-red-950 dark:text-red-200' => $form->webhook_last_error, 'bg-green-50 text-green-800 dark:bg-green-950 dark:text-green-200' => ! $form->webhook_last_error])>{{ $form->webhook_last_error ? 'Failed' : 'Delivered' }}</span>
                        <span class="min-w-0 flex-1">Last delivery {{ $form->webhook_last_at->diffForHumans() }}: {{ $form->webhook_last_error ? $form->webhook_last_error : 'HTTP '.$form->webhook_last_status.'.' }}</span>
                    @else
                        <span class="min-w-0 flex-1">Nothing sent yet. Send a test event to check your endpoint.</span>
                    @endif
                    <flux:button size="sm" variant="primary" wire:click="sendTestWebhook">Send a test event</flux:button>
                </div>
                @if ($webhookTestResult)
                    <p class="text-sm" role="status">{{ $webhookTestResult }}</p>
                @endif
                <div x-data="{ shown: false }" class="flex flex-col gap-2">
                    <p class="text-sm font-semibold">Signing secret</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <code class="rounded-md bg-zinc-100 px-2.5 py-1.5 text-sm break-all dark:bg-zinc-800" x-text="shown ? {{ Js::from($form->webhook_secret) }} : 'whsec_••••••••'">whsec_••••••••</code>
                        <flux:button size="sm" x-on:click="shown = ! shown"><span x-text="shown ? 'Hide' : 'Show'">Show</span></flux:button>
                        <flux:button size="sm" x-on:click="navigator.clipboard.writeText({{ Js::from($form->webhook_secret) }}); $el.querySelector('span').textContent = 'Copied'"><span>Copy</span></flux:button>
                    </div>
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">Every request carries a <span class="font-mono">FormHaven-Signature: t=&lt;unix time&gt;,v1=&lt;signature&gt;</span> header, where the signature is the hex HMAC-SHA256 of <span class="font-mono">&lt;t&gt;.&lt;raw body&gt;</span> with this secret. Compare it in constant time and reject old timestamps.</p>
                    <details class="text-sm">
                        <summary class="cursor-pointer font-semibold">Check the signature in Node</summary>
                        <pre class="mt-2 overflow-x-auto rounded-lg bg-zinc-900 p-4 text-sm text-zinc-100"><code>{{ implode("\n", [
                            '// Node: check a FormHaven webhook',
                            "const [t, v1] = req.headers['formhaven-signature'].split(',').map(p => p.split('=')[1]);",
                            "const expected = crypto.createHmac('sha256', SECRET).update(`\${t}.\${rawBody}`).digest('hex');",
                            'const valid = v1?.length === expected.length && crypto.timingSafeEqual(Buffer.from(v1), Buffer.from(expected))',
                            '  && Math.abs(Date.now() / 1000 - Number(t)) < 300;',
                        ]) }}</code></pre>
                    </details>
                </div>
            </div>
        @endif
    </section>

    {{-- Privacy text --}}
    <section class="flex flex-col gap-5" x-show="tab === 'privacy'" x-cloak x-data="{ lang: 'en' }">
        <div class="flex flex-col gap-2">
            <flux:heading size="lg" level="2">For the site's privacy policy</flux:heading>
            <p class="max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">Paste the paragraph into the privacy page of the site this form is on. It is filled in from this form's settings, so copy it again after you change how long messages are kept.</p>
        </div>
        <div class="flex flex-wrap gap-2" role="tablist" aria-label="Language">
            @foreach (['en' => 'English', 'nl' => 'Nederlands', 'de' => 'Deutsch'] as $code => $languageName)
                <button type="button" role="tab" x-on:click="lang = '{{ $code }}'" x-bind:aria-selected="lang === '{{ $code }}'" x-bind:class="lang === '{{ $code }}' ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'border border-zinc-300 dark:border-zinc-600'" class="rounded-lg px-3 py-1.5 text-sm font-medium">{{ $languageName }}</button>
            @endforeach
        </div>
        @foreach ($privacyTexts as $code => $privacy)
            <div class="flex flex-col gap-4" x-show="lang === '{{ $code }}'" @if ($code !== 'en') style="display: none" @endif>
                <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="font-semibold">{{ $privacy['heading'] }}</p>
                    <p class="max-w-3xl text-sm leading-relaxed">{{ $privacy['paragraph'] }}</p>
                    <flux:button size="sm" icon="clipboard" class="self-start" x-on:click="navigator.clipboard.writeText({{ Js::from(implode(chr(10).chr(10), [$privacy['heading'], $privacy['paragraph']])) }}); $el.querySelector('span').textContent = 'Copied'"><span>Copy paragraph</span></flux:button>
                </div>
                <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                    <p class="font-semibold">Above the form's Send button</p>
                    <pre class="overflow-x-auto rounded-lg bg-zinc-900 p-4 text-sm text-zinc-100"><code>{{ $privacy['form_notice_html'] }}</code></pre>
                    <flux:button size="sm" icon="clipboard" class="self-start" x-on:click="navigator.clipboard.writeText({{ Js::from($privacy['form_notice_html']) }}); $el.querySelector('span').textContent = 'Copied'"><span>Copy line</span></flux:button>
                </div>
                <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ $privacy['note'] }}</p>
            </div>
        @endforeach
    </section>
</div>
