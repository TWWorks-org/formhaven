<?php

namespace App\Actions;

use App\Models\Form;
use App\Support\DidMyFormSend;
use App\Support\WebhookTarget;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A form's general settings (name, website, thank-you page, storage, webhook, allowed
 * sites, DidMyFormSend key), in one place, so the dashboard, the REST API and MCP accept exactly
 * the same thing.
 */
class FormSettings
{
    public const RULES = [
        'name' => ['sometimes', 'required', 'string', 'max:100'],
        'site_url' => ['sometimes', 'nullable', 'url:https,http', 'max:2048'],
        'redirect_url' => ['sometimes', 'nullable', 'url:https,http', 'max:2048'],
        'retention_days' => ['sometimes', 'required', 'integer', 'min:1', 'max:365'],
        'store_submissions' => ['sometimes', 'boolean'],
        'webhook_url' => ['sometimes', 'nullable', 'url:https', 'max:2048'],
        // A list, or text with one domain per line (commas and spaces work too), as the dashboard sends it.
        'allowed_domains' => ['sometimes', 'nullable'],
        // DidMyFormSend's heartbeat key (App\Support\DidMyFormSend). Null or empty removes it.
        'monitor_key' => ['sometimes', 'nullable', 'string', 'regex:'.DidMyFormSend::KEY_PATTERN],
    ];

    public const MESSAGES = [
        'monitor_key.regex' => DidMyFormSend::KEY_MESSAGE,
        'monitor_key.string' => DidMyFormSend::KEY_MESSAGE,
    ];

    /**
     * Checks everything without saving, so a caller combining several actions can refuse the
     * whole change before any part of it is stored.
     *
     * @throws ValidationException
     */
    public static function check(array $input): array
    {
        // A pasted key often carries a space or a line break at either end.
        if (is_string($input['monitor_key'] ?? null)) {
            $input['monitor_key'] = trim($input['monitor_key']);
        }

        $data = Validator::make($input, self::RULES, self::MESSAGES)->validate();

        foreach (['site_url', 'redirect_url', 'webhook_url', 'monitor_key'] as $key) {
            if (array_key_exists($key, $data)) {
                $data[$key] = filled($data[$key]) ? $data[$key] : null;
            }
        }

        if (array_key_exists('allowed_domains', $data)) {
            $data['allowed_domains'] = self::domains($data['allowed_domains']) ?: null;
        }

        if (filled($data['webhook_url'] ?? null)) {
            try {
                WebhookTarget::check($data['webhook_url']);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['webhook_url' => $e->getMessage()]);
            }
        }

        return $data;
    }

    /** @throws ValidationException */
    public function update(Form $form, array $input): Form
    {
        $data = self::check($input);

        // Not fillable, so no other mass assignment can set it. A new or removed key starts with no refusal on record.
        if (array_key_exists('monitor_key', $data)) {
            $form->forceFill(['monitor_key' => $data['monitor_key'], 'monitor_refused_at' => null]);
            unset($data['monitor_key']);
        }

        $form->update($data);

        // A secret appears with the first webhook address and stays when the address changes.
        if ($form->webhook_url && ! $form->webhook_secret) {
            $form->forceFill(['webhook_secret' => 'whsec_'.Str::random(40)])->save();
        }

        return $form;
    }

    /**
     * What the owner typed as the stored list of hosts: "www." and "https://" dropped, duplicates removed.
     *
     * @throws ValidationException under the key "allowed_domains"
     */
    public static function domains(mixed $typed): array
    {
        $entries = match (true) {
            $typed === null => [],
            is_string($typed) => preg_split('/[\s,]+/', $typed, -1, PREG_SPLIT_NO_EMPTY),
            is_array($typed) && array_is_list($typed) => $typed,
            default => throw ValidationException::withMessages(['allowed_domains' => 'Send allowed_domains as a list of domains, or text with one per line.']),
        };

        $domains = [];
        foreach ($entries as $entry) {
            if (! is_string($entry)) {
                throw ValidationException::withMessages(['allowed_domains' => 'Every allowed domain must be text.']);
            }
            if (trim($entry) === '') {
                continue;
            }
            if (! $domain = Form::normalizeDomain($entry)) {
                throw ValidationException::withMessages(['allowed_domains' => '"'.Str::limit($entry, 60).'" is not a domain. Use a name like example.nl, one per line.']);
            }
            $domains[$domain] = true;
        }

        if (count($domains) > Form::MAX_ALLOWED_DOMAINS) {
            throw ValidationException::withMessages(['allowed_domains' => 'List up to '.Form::MAX_ALLOWED_DOMAINS.' sites. A domain already covers its subdomains.']);
        }

        return array_keys($domains);
    }
}
