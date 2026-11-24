<?php

namespace App\Support;

/**
 * What a number of form units costs, from config/billing.php: the first full_price_forms at the
 * full price, every unit after them at the lower one, never fewer than min_forms. Stripe bills the
 * same sum from one tiered price per interval (graduated tiers); this is the copy every page, the
 * billing screen and the admin income read, so none of them multiplies units by one price.
 */
class Bill
{
    /** The monthly bill in euros for $units form units. */
    public static function monthly(int $units): float
    {
        return self::sum($units, 'monthly');
    }

    /** The yearly bill in euros for $units form units. */
    public static function yearly(int $units): float
    {
        return self::sum($units, 'yearly');
    }

    /** "€20" for whole amounts, "€17.50" for the rest; never "€17.5" or "€20.00". */
    public static function format(float $eur, string $sign = '€'): string
    {
        $cents = (int) round($eur * 100);

        return $sign.($cents % 100 === 0 ? (string) intdiv($cents, 100) : number_format($cents / 100, 2, '.', ''));
    }

    /** The German way to write the same amount: "20 €", "0,50 €", with a non-breaking space before the sign. */
    public static function formatDe(float $eur): string
    {
        $cents = (int) round($eur * 100);

        return ($cents % 100 === 0 ? (string) intdiv($cents, 100) : number_format($cents / 100, 2, ',', '')).("\u{00A0}€");
    }

    /** The first form at the lower price, as copy says it: "11th". */
    public static function firstLowerForm(): string
    {
        $n = config('billing.per_form.full_price_forms') + 1;
        $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');

        return $n.$suffix;
    }

    private static function sum(int $units, string $interval): float
    {
        $p = config('billing.per_form');
        $units = max($p['min_forms'], $units);
        $full = min($units, $p['full_price_forms']);

        return $full * $p["{$interval}_eur"] + ($units - $full) * $p["lower_{$interval}_eur"];
    }
}
