<?php

namespace App\Actions;

use App\Models\Form;
use App\Models\FormClaim;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The agent start flow. An agent with no account reserves a form address and gets a link for
 * the site owner plus a setup key. Nothing a visitor sends is stored until the owner opens the
 * link, has an account (so has accepted the terms and the DPA) and presses Activate. Only then
 * does the setup key start working on the REST API and the MCP server.
 */
class StartForm
{
    public const RULES = CreateForm::RULES + [
        'client' => ['nullable', 'string', 'max:60'],
    ];

    /** @return array{claim: FormClaim, claim_token: string, setup_key: string} */
    public function start(array $input, ?string $ip): array
    {
        $data = Validator::make($input, self::RULES)->validate();

        do {
            $publicId = Str::lower(Str::random(16));
        } while (Form::where('public_id', $publicId)->exists() || FormClaim::where('public_id', $publicId)->exists());

        $claimToken = Str::random(48);
        // No "|" in it: Sanctum then looks the whole string up by its SHA-256, which is what we store.
        $setupKey = 'fhs_'.Str::random(48);

        $claim = FormClaim::create([
            'public_id' => $publicId,
            'name' => $data['name'],
            'site_url' => $data['site_url'] ?? null,
            'redirect_url' => $data['redirect_url'] ?? null,
            'retention_days' => $data['retention_days'] ?? null,
            'client' => isset($data['client']) ? trim($data['client']) ?: null : null,
            'claim_token_hash' => hash('sha256', $claimToken),
            'setup_key_hash' => hash('sha256', $setupKey),
            // "fhs_" plus 4 of the 48 random characters: enough to recognise, too little to guess from.
            'setup_key_prefix' => substr($setupKey, 0, self::PREFIX_LENGTH),
            'ip_hash' => hash_hmac('sha256', (string) $ip, config('app.key')),
        ]);

        return ['claim' => $claim, 'claim_token' => $claimToken, 'setup_key' => $setupKey];
    }

    /** @throws \Illuminate\Validation\ValidationException when the address is unconfirmed, or the trial has no room for another form or has ended */
    public function claim(FormClaim $claim, User $user): Form
    {
        // The setup key can add recipients, whose confirmation emails go out in this account's name.
        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages(['claim' => 'Confirm your email address first: press the link we sent to '.$user->email.', then press Activate again.']);
        }

        return DB::transaction(function () use ($claim, $user) {
            $form = app(CreateForm::class)->handle($user, array_filter([
                'name' => $claim->name,
                'site_url' => $claim->site_url,
                'redirect_url' => $claim->redirect_url,
                'retention_days' => $claim->retention_days,
            ], fn ($v) => $v !== null), $claim->public_id);

            // The key reaches this one form only. A full key here would let whoever started the form
            // read every other form on the account that activated it (User::formsForKey()).
            // It expires (config sanctum.setup_key_days): an agent's key outlives the setup it was for.
            $user->tokens()->create([
                'name' => Str::limit('Agent setup: '.$claim->name, 255, ''),
                'token' => $claim->setup_key_hash,
                'abilities' => [User::FORM_ABILITY.$claim->public_id],
                'expires_at' => now()->addDays(self::setupKeyDays()),
            ]);

            $claim->forceFill(['user_id' => $user->id, 'claimed_at' => now()])->save();

            return $form;
        });
    }

    /** Days a setup key works after the form is activated. */
    public static function setupKeyDays(): int
    {
        return max(1, (int) config('sanctum.setup_key_days'));
    }

    /** The first characters of a setup key, shown on the form's page so the owner can tell keys apart. */
    public const PREFIX_LENGTH = 8;
}
