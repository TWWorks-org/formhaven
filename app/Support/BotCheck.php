<?php

namespace App\Support;

use App\Models\Form;
use Illuminate\Support\Facades\Cache;

/**
 * An invisible proof-of-work check, run by /bot-check.js on the visitor's page.
 *
 * We pick a secret number and send its SHA-256 hash (salt + number) with an HMAC
 * signature. The browser tries numbers until one hashes to it, which takes a real
 * browser a second or two and costs a bot that never runs our script the whole post.
 * The payload follows ALTCHA's v1 format (algorithm, challenge, number, salt, signature).
 *
 * Nothing about the visitor is stored or sent anywhere else: no cookies, no third party.
 */
final class BotCheck
{
    /** The hidden field bot-check.js fills in. Underscored, so it is never stored with the answers. */
    public const FIELD = '_botcheck';

    /** Highest secret number: a browser tries about half of these, typically within a second or two. */
    public const MAX_NUMBER = 50000;

    /** How long a puzzle stays valid, so a page left open for a while still sends. */
    public const TTL_SECONDS = 7200;

    public static function challenge(Form $form): array
    {
        $expires = now()->getTimestamp() + self::TTL_SECONDS;
        $salt = bin2hex(random_bytes(12)).'?form='.$form->public_id.'&expires='.$expires;
        $challenge = hash('sha256', $salt.random_int(0, self::MAX_NUMBER));

        return [
            'algorithm' => 'SHA-256',
            'challenge' => $challenge,
            'maxnumber' => self::MAX_NUMBER,
            'salt' => $salt,
            'signature' => self::sign($challenge),
        ];
    }

    /**
     * True for a solved, unexpired puzzle made for this form, and marks it used in the same step.
     * Cache::add only succeeds once, so parallel posts replaying one solution cannot all pass.
     */
    public static function claim(Form $form, mixed $payload): bool
    {
        $challenge = self::solvedChallenge($form, $payload);

        return $challenge !== null && Cache::add('botcheck:'.$challenge, true, self::TTL_SECONDS + 60);
    }

    private static function solvedChallenge(Form $form, mixed $payload): ?string
    {
        $data = is_string($payload) && strlen($payload) < 2000 ? json_decode((string) base64_decode($payload, true), true) : null;

        if (! is_array($data)) {
            return null;
        }

        $challenge = $data['challenge'] ?? null;
        $salt = $data['salt'] ?? null;
        $number = $data['number'] ?? null;
        $signature = $data['signature'] ?? null;

        if (! is_string($challenge) || ! is_string($salt) || ! is_string($signature) || ! is_int($number)
            || $number < 0 || $number > self::MAX_NUMBER || ! hash_equals(self::sign($challenge), $signature)) {
            return null;
        }

        parse_str(str_contains($salt, '?') ? substr($salt, strpos($salt, '?') + 1) : '', $params);
        $expires = (int) ($params['expires'] ?? 0);

        $solved = ($params['form'] ?? null) === $form->public_id && $expires >= now()->getTimestamp() && hash_equals($challenge, hash('sha256', $salt.$number));

        return $solved ? $challenge : null;
    }

    private static function sign(string $challenge): string
    {
        return hash_hmac('sha256', $challenge, 'botcheck|'.config('app.key'));
    }
}
