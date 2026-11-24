<?php

namespace Tests\Feature;

use App\Support\Bill;
use Tests\TestCase;

/**
 * Every price, form count and grace figure a reader sees must come from
 * config/billing.php. A hard-coded "€6" or "3 forms" left behind after a price
 * change fails here. Pages are checked on their own <main>, not the shared
 * footer, so one page's copy cannot hide behind another's.
 */
class CopyConsistencyTest extends TestCase
{
    private const PAGES = ['/', '/pricing', '/legal/terms', '/legal/dpa', '/legal/privacy', '/templates', '/templates/withdrawal-form', '/templates/contact-form'];

    private function mainOf(string $html): string
    {
        preg_match('#<main\b[^>]*>(.*)</main>#s', $html, $m);
        // A rival's own prices (marked data-rival on comparison pages) may be in euros too; they are not ours to match.
        $main = preg_replace('#<(td|div)\b[^>]*\bdata-rival\b[^>]*>.*?</\1>#s', '', $m[1] ?? '');
        // A line of the pricing calculator's sum (15 forms at €5 a year: "€75 a year") is part of a bill, not a bill;
        // PricingCalculatorTest checks each one against its form count and rate instead.
        $main = preg_replace('#<span\b[^>]*\bdata-subtotal\b[^>]*>[^<]*</span>#', '', $main);

        return html_entity_decode(strip_tags($main));
    }

    private function texts(): array
    {
        $texts = [];
        $guides = array_map(fn ($s) => "/guides/{$s}", array_keys(\App\Http\Controllers\ContentController::guides()));
        $alternatives = array_map(fn ($s) => "/alternatives/{$s}", array_keys(\App\Http\Controllers\ContentController::alternatives()));
        foreach ([...self::PAGES, ...$guides, ...$alternatives] as $path) {
            $texts[$path] = $this->mainOf($this->get($path)->assertOk()->getContent());
            $this->assertGreaterThan(200, strlen($texts[$path]), "{$path} has no <main> to check");
        }
        $texts['/llms.txt'] = $this->get('/llms.txt')->assertOk()->getContent();

        return $texts;
    }

    /** Cents, so €17.50 compares exactly. */
    private static function cents(float $eur): int
    {
        return (int) round($eur * 100);
    }

    /**
     * A bill a page may state: what Bill gives for some whole number of form units at or above the
     * minimum, and a month may carry whole extra thousands on top. A yearly invoice never does (extra
     * thousands are invoiced per month), so a year is the form units alone.
     */
    private static function isBill(int $cents, string $period): bool
    {
        $p = config('billing.per_form');
        $extra = self::cents($p['extra_eur']);

        for ($units = $p['min_forms']; $units <= 10000; $units++) {
            $bill = self::cents($period === ' a year' ? Bill::yearly($units) : Bill::monthly($units));
            if ($bill > $cents) {
                return false;
            }
            if ($bill === $cents || ($period === ' a month' && ($cents - $bill) % $extra === 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Graduated per-form pricing makes derived amounts legitimate (25 forms: €17.50 a month), so the
     * rule is: a bill ("€N a month" or "€N a year", N may have cents) is one Bill can produce (see
     * isBill); any other euro amount is one of the unit prices in config.
     */
    public function test_every_euro_amount_is_a_price_from_config(): void
    {
        $p = config('billing.per_form');
        $units = array_map(self::cents(...), [$p['monthly_eur'], $p['yearly_eur'], $p['lower_monthly_eur'], $p['lower_yearly_eur'], $p['extra_eur']]);

        foreach ($this->texts() as $path => $text) {
            // A price read from a config key that no longer exists renders as a bare euro sign.
            // German copy writes the sign after the amount ("0,50 €", Bill::formatDe), so a digit before it counts too.
            $this->assertDoesNotMatchRegularExpression('/(?<!\d[\s\x{00A0}])€(?![\s\x{00A0}]?\d)/u', $text, "{$path} shows a euro sign with no amount");
            preg_match_all('/€\s?(\d+(?:[.,]\d+)?)( a month| a year)?/u', $text, $m, PREG_SET_ORDER);
            foreach ($m as $match) {
                [$all, $amount, $period] = [$match[0], self::cents((float) str_replace(',', '.', $match[1])), $match[2] ?? ''];
                $ok = $period !== '' ? self::isBill($amount, $period) : in_array($amount, $units, true);
                $this->assertTrue($ok, "{$path} says \"{$all}\", which is no price or bill from config/billing.php");
                // Cents are always two digits: "€17.50", never "€17.5" or "€20.00".
                $this->assertMatchesRegularExpression('/^\d+(\.(?!00)\d\d)?$/', $match[1], "{$path} writes \"{$all}\"; use Bill::format");
            }
            // The German page: "5 € im Monat" is a bill, any other "N €" a unit price, cents after a comma.
            preg_match_all('/(\d+(?:[.,]\d+)?)[\s\x{00A0}]?€( im Monat| im Jahr)?/u', $text, $m, PREG_SET_ORDER);
            foreach ($m as $match) {
                [$all, $amount, $period] = [$match[0], self::cents((float) str_replace(',', '.', $match[1])), ['' => '', ' im Monat' => ' a month', ' im Jahr' => ' a year'][$match[2] ?? '']];
                $ok = $period !== '' ? self::isBill($amount, $period) : in_array($amount, $units, true);
                $this->assertTrue($ok, "{$path} says \"{$all}\", which is no price or bill from config/billing.php");
                $this->assertMatchesRegularExpression('/^\d+(,(?!00)\d\d)?$/', $match[1], "{$path} writes \"{$all}\"; use Bill::formatDe");
            }
        }
    }

    /**
     * A wrong bill in the example table can still be some other legitimate bill (€25 a month is 5 forms
     * plus 20 extra thousands), so the rule above cannot see it. Each row is checked against its own forms.
     */
    public function test_the_example_bills_match_their_form_counts(): void
    {
        foreach (['/', '/pricing'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();
            preg_match('#<caption class="sr-only">What your bill comes to</caption>.*?<tbody>(.*?)</tbody>#s', $html, $table);
            preg_match_all('#<tr\b.*?</tr>#s', $table[1] ?? '', $rows);
            $this->assertGreaterThanOrEqual(4, count($rows[0]), "{$path} has no example table to check");

            foreach ($rows[0] as $row) {
                preg_match_all('#<td\b[^>]*>(.*?)</td>#s', $row, $cells);
                $cells = array_map(fn ($c) => trim(html_entity_decode(strip_tags($c))), $cells[1]);
                preg_match('/(\d+)$/', $cells[0], $forms);
                $forms = (int) $forms[1];
                $this->assertSame(number_format($forms * config('billing.per_form.submissions')), $cells[1], "{$path}: {$forms} forms include the wrong submissions");
                // The bill is one cell on the home page and a monthly and a yearly column on /pricing.
                $billed = implode(' ', array_slice($cells, 2));
                $this->assertStringContainsString(Bill::format(Bill::monthly($forms)).' a month', $billed, "{$path}: wrong monthly bill for {$forms} forms");
                $this->assertStringContainsString(Bill::format(Bill::yearly($forms)).' a year', $billed, "{$path}: wrong yearly bill for {$forms} forms");
            }
        }
    }

    public function test_every_form_count_matches_the_minimum_or_the_trial_cap_from_config(): void
    {
        $p = config('billing.per_form');
        $trialForms = config('billing.trial_forms');

        foreach ($this->texts() as $path => $text) {
            preg_match_all('/at least (\d+) forms|mindestens (\d+) Formulare/', $text, $m);
            foreach (array_filter([...$m[1], ...$m[2]], fn ($count) => $count !== '') as $count) {
                $this->assertSame((string) $p['min_forms'], $count, "{$path} says at least {$count} forms; the minimum in config/billing.php is {$p['min_forms']}");
            }
            preg_match_all('/[Uu]p to (\d+) forms (?:while you try|during the trial)|trial[^.]{0,40}?(\d+) forms/', $text, $m, PREG_SET_ORDER);
            foreach ($m as $match) {
                $count = ($match[2] ?? '') !== '' ? $match[2] : $match[1];
                $this->assertSame((string) $trialForms, $count, "{$path} says {$count} forms on the trial; config/billing.php says {$trialForms}");
            }
        }
    }

    /**
     * At €1 a form every whole number of euros is a possible bill, so a stale "€19 a month" would pass
     * the rule above. The three named plans were retired on 2026-11-20: none may come back in our copy.
     */
    public function test_no_page_names_the_retired_plans(): void
    {
        foreach ($this->texts() as $path => $text) {
            foreach (['/Solo/', '/Agency Pro/', '/Agency (plan|limits)/', '/[Pp]lans start/', '/[Pp]ick a plan/'] as $retired) {
                $this->assertDoesNotMatchRegularExpression($retired, $text, "{$path} still names a retired plan ({$retired})");
            }
        }
    }

    public function test_the_grace_share_is_stated_from_config_wherever_the_limit_is_explained(): void
    {
        $grace = (int) round(config('billing.email_grace') * 100).'%';
        $texts = $this->texts();

        foreach (['/', '/pricing', '/legal/terms', '/llms.txt'] as $path) {
            $this->assertStringContainsString($grace, $texts[$path], "{$path} does not state the {$grace} email grace");
        }
    }
}
