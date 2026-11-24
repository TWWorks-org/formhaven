<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Limits on the account emails a visitor can cause: address confirmation, password reset, the
 * notice to the old address on an email change and the account deletion link. Mail goes out
 * on a daily quota shared with other sites, so a script must not be able to spend it or use
 * FormHaven to flood someone's inbox.
 *
 * Layers (sized so account mail stays a small part of the shared daily quota, with each
 * window's reason beside it):
 *  - per visitor: PER_IP a day, per IPv4 address or per IPv6 /64 (one connection is given a
 *    whole /64, so counting single IPv6 addresses would let it rotate for free);
 *  - per address: PER_ADDRESS an hour, counted whether or not an account exists (so the limit
 *    tells nobody which addresses have one); a short window, so someone who spends it on a
 *    stranger's address blocks that person's reset for an hour at most;
 *  - two daily pools in MailBudget, both apart from submission mail: SIGNUP for accounts that
 *    never proved their address (anyone can make one) and ACCOUNT for those that did
 *    (User::accountMailPool). A flood of signups spends only SIGNUP; resets and re-confirmation
 *    for real accounts keep their own room.
 *
 * allow() is asked before anything is sent and counts the first two; the pool is taken in the
 * one place each email is sent (User, the profile and deletion components), so an address
 * without an account costs no pool. Keys hold a keyed hash, never the IP or the address.
 */
class AccountMail
{
    public const PER_IP = 10;

    public const PER_ADDRESS = 3;

    /**
     * The message to show when this account email may not go, or null when it may (and is
     * counted). $pool is the pool it will come out of, checked for room first.
     */
    public static function allow(string $kind, string $address, string $pool): ?string
    {
        $ipKey = 'account-mail:ip:'.self::hash(self::visitor((string) request()->ip()));
        $addressKey = 'account-mail:address:'.self::hash(Str::lower(trim($address)));

        if (RateLimiter::tooManyAttempts($ipKey, self::PER_IP)) {
            SecurityLog::event('account_mail.throttled', ['kind' => $kind, 'limit' => 'ip']);

            return 'Too many account emails were asked for from your connection today. Try again tomorrow, or write to '.config('legal.email').'.';
        }

        if (RateLimiter::tooManyAttempts($addressKey, self::PER_ADDRESS)) {
            SecurityLog::event('account_mail.throttled', ['kind' => $kind, 'limit' => 'address']);

            return 'Too many account emails were asked for this address in the last hour. Use the newest one you got, or try again in '.max(1, (int) ceil(RateLimiter::availableIn($addressKey) / 60)).' minutes.';
        }

        if (MailBudget::remaining($pool) === 0) {
            SecurityLog::event('account_mail.throttled', ['kind' => $kind, 'limit' => 'daily', 'pool' => $pool]);

            return 'FormHaven has sent all the account emails it can today. Try again tomorrow, or write to '.config('legal.email').'.';
        }

        RateLimiter::hit($ipKey, 86400);
        RateLimiter::hit($addressKey, 3600);

        return null;
    }

    /** Takes one email from this account's daily pool; false (and logged) when none is left. */
    public static function take(string $kind, User $user): bool
    {
        $pool = $user->accountMailPool();

        if (! MailBudget::tryTake($pool)) {
            SecurityLog::event('account_mail.held', ['kind' => $kind, 'pool' => $pool, 'user_id' => $user->id]);

            return false;
        }

        if (MailBudget::remaining($pool) === 0) {
            SecurityLog::event('account_mail.pool_spent', ['pool' => $pool, 'limit' => MailBudget::limit($pool)]);
        }

        return true;
    }

    /**
     * The visitor a per-IP limit counts: an IPv4 address, or the /64 an IPv6 address sits in. An
     * IPv4 address written as IPv6 (mapped ::ffff:a.b.c.d, or the old compatible ::a.b.c.d) counts
     * as that IPv4 address: as a /64 every such visitor would share one count.
     */
    public static function visitor(string $ip): string
    {
        $packed = str_contains($ip, ':') ? @inet_pton($ip) : false;

        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        $prefix = substr($packed, 0, 12);
        $v4 = substr($packed, 12);
        if ($prefix === str_repeat("\0", 10)."\xff\xff"
            || ($prefix === str_repeat("\0", 12) && ltrim($v4, "\0") !== '' && $v4 !== "\0\0\0\1")) {
            return inet_ntop($v4);
        }

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
    }

    private static function hash(string $value): string
    {
        return substr(hash_hmac('sha256', $value, (string) config('app.key')), 0, 40);
    }
}
