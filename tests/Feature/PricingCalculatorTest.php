<?php

namespace Tests\Feature;

use App\Support\Bill;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The pricing page's calculator is right with JavaScript off: the server renders the default bill and
 * the sample bills from App\Support\Bill, and the script gets its rates from config/billing.php only.
 * Each check runs on the real config and on a changed one, so a rate typed into the Blade fails.
 */
class PricingCalculatorTest extends TestCase
{
    private const KEYS = ['monthly_eur', 'yearly_eur', 'lower_monthly_eur', 'lower_yearly_eur', 'full_price_forms', 'min_forms', 'submissions', 'extra_eur'];

    public static function configs(): array
    {
        return [
            'the real config' => [[]],
            'changed rates' => [['monthly_eur' => 3, 'yearly_eur' => 30, 'lower_monthly_eur' => 1.5, 'lower_yearly_eur' => 15, 'full_price_forms' => 8, 'min_forms' => 4, 'submissions' => 500, 'extra_eur' => 2]],
        ];
    }

    private static function text(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))));
    }

    #[DataProvider('configs')]
    public function test_the_page_renders_the_default_bill_the_samples_and_the_rates_from_config(array $changes): void
    {
        foreach ($changes as $key => $value) {
            config(["billing.per_form.{$key}" => $value]);
        }
        $p = config('billing.per_form');
        $html = $this->get('/pricing')->assertOk()->getContent();

        // The rates the script works from are exactly the config's.
        preg_match('#<script type="application/json" id="price-rates">(.*?)</script>#s', $html, $json);
        $this->assertNotEmpty($json, 'no rates handed to the calculator script');
        $this->assertEquals(array_intersect_key($p, array_flip(self::KEYS)), json_decode($json[1], true));
        $this->assertCount(count(self::KEYS), json_decode($json[1], true));

        // The default bill: 25 forms, yearly.
        preg_match('#<span id="price-total"[^>]*>(.*?)</span>#s', $html, $total);
        preg_match('#<span id="price-unit"[^>]*>(.*?)</span>#s', $html, $unit);
        $this->assertSame(Bill::format(Bill::yearly(25)), self::text($total[1] ?? ''));
        $this->assertSame('a year for 25 forms', self::text($unit[1] ?? ''));

        // Its itemised lines: each a form count at a configured yearly rate, adding up to the total.
        preg_match('#<ul id="price-lines"[^>]*>(.*?)</ul>#s', $html, $list);
        preg_match_all('#<li\b[^>]*>\s*<span[^>]*>(.*?)</span>\s*<span[^>]*>(.*?)</span>\s*</li>#s', $list[1] ?? '', $lines, PREG_SET_ORDER);
        $this->assertCount(3, $lines, '25 forms should show a full-rate line, a lower-rate line and the submissions');
        $expected = [
            [$p['full_price_forms'].' forms × '.Bill::format($p['yearly_eur']).' per form a year', Bill::format($p['full_price_forms'] * $p['yearly_eur']).' a year'],
            [(25 - $p['full_price_forms']).' forms × '.Bill::format($p['lower_yearly_eur']).' per form a year', Bill::format((25 - $p['full_price_forms']) * $p['lower_yearly_eur']).' a year'],
            ['Submissions included', number_format(25 * $p['submissions']).' a month'],
        ];
        foreach ($lines as $i => $line) {
            $this->assertSame($expected[$i], [self::text($line[1]), self::text($line[2])], "line {$i} of the default bill");
        }
        $this->assertEqualsWithDelta(Bill::yearly(25), $p['full_price_forms'] * $p['yearly_eur'] + (25 - $p['full_price_forms']) * $p['lower_yearly_eur'], 0.001);
        $this->assertStringContainsString('include <strong id="price-pool" class="font-mono tabular-nums">'.number_format(25 * $p['submissions']).'</strong> submissions', $html);

        // The sample bills, monthly and yearly, for each form count.
        preg_match('#<caption class="sr-only">What your bill comes to</caption>.*?<tbody>(.*?)</tbody>#s', $html, $table);
        preg_match_all('#<tr\b.*?</tr>#s', $table[1] ?? '', $rows);
        $counts = [$p['min_forms'], $p['full_price_forms'], 25, 50, 100];
        $this->assertCount(count($counts), $rows[0]);
        foreach ($rows[0] as $i => $row) {
            preg_match_all('#<td\b[^>]*>(.*?)</td>#s', $row, $cells);
            $forms = $counts[$i];
            $this->assertSame([
                $forms === $p['min_forms'] ? '1 to '.$forms : (string) $forms,
                number_format($forms * $p['submissions']),
                Bill::format(Bill::monthly($forms)).' a month',
                Bill::format(Bill::yearly($forms)).' a year',
            ], array_map(self::text(...), $cells[1]), "sample bill for {$forms} forms");
        }

        // The price list: every rate carries its unit, the yearly ones what they save.
        $main = self::text(preg_replace('#<script\b.*?</script>#s', '', $html));
        foreach ([
            Bill::format($p['monthly_eur']).' per form a month',
            Bill::format($p['yearly_eur']).' per form a year 2 months free',
            Bill::format($p['lower_monthly_eur']).' per form a month',
            Bill::format($p['lower_yearly_eur']).' per form a year 2 months free',
            Bill::format($p['extra_eur']).' per started 1,000',
            Bill::format(Bill::monthly($p['min_forms'])).' a month',
            Bill::format(Bill::yearly($p['min_forms'])).' a year',
            'Forms 1 to '.$p['full_price_forms'],
            'Form '.($p['full_price_forms'] + 1).' and every form after it',
            'Yearly, 2 months free',
        ] as $text) {
            $this->assertStringContainsString($text, $main);
        }
    }

    public function test_months_free_is_only_claimed_when_both_rates_save_the_same_whole_months(): void
    {
        config(['billing.per_form.lower_yearly_eur' => 4.6]);
        $html = $this->get('/pricing')->assertOk()->getContent();

        $this->assertStringNotContainsString('months free', $html);
        $this->assertStringNotContainsString('month free', $html);
        $this->assertStringContainsString('€4.60 per form a year', html_entity_decode(strip_tags($html)));

        config(['billing.per_form.lower_yearly_eur' => 5.5, 'billing.per_form.yearly_eur' => 11]);
        $this->assertStringContainsString('1 month free', $this->get('/pricing')->getContent());
    }

    public function test_a_yearly_figure_never_appears_without_its_unit(): void
    {
        $p = config('billing.per_form');
        $text = self::text(preg_replace('#<script\b.*?</script>#s', '', $this->get('/pricing')->getContent()));

        // Every yearly bill and yearly rate from config, wherever it appears, is followed by "a year".
        $yearly = [Bill::format($p['yearly_eur']), Bill::format($p['lower_yearly_eur'])];
        foreach ([$p['min_forms'], $p['full_price_forms'], 25, 50, 100] as $forms) {
            $yearly[] = Bill::format(Bill::yearly($forms));
        }
        foreach (array_unique($yearly) as $amount) {
            preg_match_all('/'.preg_quote($amount, '/').'(?![\d.])(.{0,16})/u', $text, $m);
            foreach ($m[1] as $after) {
                // €10 is both the yearly rate and the 10-form monthly bill; a month is a unit too.
                $this->assertMatchesRegularExpression('/^ (per form )?a (year|month)/', $after, "{$amount} is shown without its unit: \"{$amount}{$after}\"");
            }
        }
    }
}
