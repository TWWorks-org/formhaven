<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Submission extends Model
{
    use HasFactory;

    protected $fillable = ['data', 'spam', 'spam_reason', 'ip_hash', 'user_agent', 'referer'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'spam' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Submission $submission) {
            $submission->search_text = self::searchable(collect($submission->data)->flatten()->implode("\n"));
        });
    }

    /**
     * What a webhook receives. The same shape for a real submission and for the dashboard's
     * test event, so whatever the receiver maps from a test keeps working on real ones.
     */
    public function webhookPayload(bool $test = false): array
    {
        return [
            'event' => 'submission.created',
            'id' => $test ? 'test' : (string) $this->id,
            'test' => $test,
            'form' => ['id' => $this->form->public_id, 'name' => $this->form->name],
            'received_at' => ($this->created_at ?? now())->toIso8601String(),
            'reply_to' => $this->replyToAddress(),
            'page' => $this->sourcePage(),
            'fields' => (object) $this->data,
        ];
    }

    /**
     * For a form that does not store submissions: drop everything the visitor sent, keep the row the
     * monthly limit counts. The IP hash goes too: keyed with our app key it is still personal data.
     */
    public function forget(): void
    {
        $this->forceFill(['data' => [], 'referer' => null, 'user_agent' => null, 'ip_hash' => null])->save();
    }

    public function isForgotten(): bool
    {
        return $this->data === [];
    }

    /**
     * The dashboard's line for this submission: [who sent it, what they wrote].
     * Lives here and not on the Livewire page, where a public method taking a
     * Submission could be called from the browser with anyone's submission id.
     */
    public function summary(): array
    {
        if ($this->isForgotten()) {
            return [$this->spam ? 'Spam' : 'Emailed to you', $this->spam ? 'Spam. Not stored, because this form keeps no submissions.' : 'Emailed to you. The answers were not kept, because this form keeps no submissions.'];
        }

        $answers = collect($this->data)->map(fn ($answer) => is_array($answer) ? implode(', ', $answer) : (string) $answer)->filter(fn ($answer) => $answer !== '');
        $who = $answers->get('name') ?? $this->replyToAddress() ?? $answers->first() ?? 'Submission';

        return [$who, $answers->reject(fn ($answer) => $answer === $who)->implode(' · ')];
    }

    /** Why this is in spam, in a sentence the owner can act on; null for a real submission or an older row. */
    public function spamReason(): ?string
    {
        return match (true) {
            ! $this->spam || ! $this->spam_reason => null,
            $this->spam_reason === 'marked' => 'You marked it as spam. Press Not spam to move it back.',
            str_starts_with($this->spam_reason, 'spam_word:') && str_contains($this->spam_reason, '@') => 'It came from '.Str::after($this->spam_reason, 'spam_word:').', a sender you blocked on this form.',
            $this->spam_reason === 'honeypot' =>'Caught by the hidden trap field (_gotcha or _honeypot), which only bots fill in.',
            $this->spam_reason === 'bot_check' => 'The bot check did not pass. Bots fail it; so does a visitor whose browser runs no JavaScript, or a page without the bot-check script.',
            $this->spam_reason === 'link_markup' => 'It contains forum link code ([url=...] or <a href>), which spam bots paste and people do not.',
            $this->spam_reason === 'links' => 'It contains more than '.\App\Actions\ReceiveSubmission::MAX_LINKS.' links, which is how link spam looks.',
            str_starts_with($this->spam_reason, 'spam_word:') => 'It contains "'.Str::after($this->spam_reason, 'spam_word:').'", one of this form\'s spam words.',
            $this->spam_reason === 'no_origin' => 'The browser did not say which site this came from, and this form only accepts its allowed sites. Some privacy settings hide it.',
            default => 'Sent from '.Str::after($this->spam_reason, 'not_allowed:').', which is not one of this form\'s allowed sites. If that site is yours, add it under Settings.',
        };
    }

    /**
     * "Block this sender" in the owner's email. The sender is worked out now and signed into the link,
     * because a form that keeps nothing wipes the answers once the email has left.
     */
    public function blockUrl(): string
    {
        return \Illuminate\Support\Facades\URL::signedRoute('submissions.block', array_filter([
            'submission' => $this->id,
            'sender' => \App\Actions\BlockSender::entryFor($this->replyToAddress()),
        ]));
    }

    /** Lowercase without accents: what both the stored answers and a search term are compared as. */
    public static function searchable(string $text): string
    {
        return Str::lower(Str::ascii($text));
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** Emails our mail provider did not deliver, oldest first. */
    public function deliveryFailures(): HasMany
    {
        return $this->hasMany(DeliveryFailure::class)->orderBy('id');
    }

    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'fields' => $this->data,
            // false when the form does not store submissions: emailed, then the answers were wiped.
            'stored' => ! $this->isForgotten(),
            'spam' => $this->spam,
            'spam_reason' => $this->spamReason(),
            'received_at' => $this->created_at->toIso8601String(),
            // Addresses this message did not reach, and why; empty once it was delivered everywhere.
            'undelivered' => $this->deliveryFailures->map(fn (DeliveryFailure $failure) => [
                'email' => $failure->email,
                'reason' => $failure->explain(),
                'failed_at' => $failure->created_at->toIso8601String(),
            ])->all(),
        ];
    }

    /** The visitor's address, if they typed a valid one, so the owner can hit Reply. */
    public function replyToAddress(): ?string
    {
        foreach (['email', 'e-mail', 'mail', '_replyto'] as $key) {
            $value = $this->data[$key] ?? null;
            if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return $value;
            }
        }

        return null;
    }

    /** A field name as a person reads it: "first_name" and "_replyto" become "First name" and "Replyto". */
    public static function fieldLabel(string $key): string
    {
        return Str::ucfirst(trim(preg_replace('/[\s_\-]+/', ' ', ltrim($key, '_'))) ?: $key);
    }

    /** Who sent it, for the email heading: their name when the form asks for one, else their email address. */
    public function senderLabel(): ?string
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) ($this->data['name'] ?? '')));

        return $name !== '' ? Str::limit($name, 80) : $this->replyToAddress();
    }

    /** The answers as an email lists them: lists joined, and without _subject, which is already the subject line. */
    public function mailAnswers(): array
    {
        return collect($this->data)
            ->except('_subject')
            ->map(fn ($answer) => is_array($answer) ? implode(', ', $answer) : (string) $answer)
            ->all();
    }

    /** The email subject: the form's _subject field when the site sets one, as on Formspree. */
    public function mailSubject(): string
    {
        $subject = trim(preg_replace('/\s+/', ' ', (string) ($this->data['_subject'] ?? '')));

        return $subject !== '' ? Str::limit($subject, 150) : 'New submission: '.$this->form->name;
    }

    /** The page the form was sent from, as host and path ("example.nl/contact"), without the query. */
    public function sourcePage(): ?string
    {
        $url = parse_url((string) $this->referer);

        if (! isset($url['scheme'], $url['host']) || ! in_array($url['scheme'], ['http', 'https'], true)) {
            return null;
        }

        return $url['host'].rtrim($url['path'] ?? '', '/');
    }
}
