<?php

namespace Tests\Feature;

use App\Support\Bill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The home page's SoftwareApplication schema states the whole price list, monthly and yearly, as the
 * rate card on /pricing does. Every price is read from config/billing.php: each check runs on the real
 * config and on a changed one, so a rate typed into the partial fails.
 */
class StructuredDataOffersTest extends TestCase
{
    use RefreshDatabase;

    public static function configs(): array
    {
        return [
            'the real config' => [[]],
            'changed rates' => [['monthly_eur' => 3, 'yearly_eur' => 30, 'lower_monthly_eur' => 1.5, 'lower_yearly_eur' => 15, 'full_price_forms' => 8, 'min_forms' => 4, 'submissions' => 500, 'extra_eur' => 2]],
        ];
    }

    /** Prices print as plain decimals with two places, as Stripe shows them: "0.50", never "0.5" or "€0.50". */
    private static function money(float $eur): string
    {
        return number_format($eur, 2, '.', '');
    }

    private function softwareApplication(): array
    {
        $html = $this->get('/')->assertOk()->getContent();
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        foreach ($m[1] as $raw) {
            $data = json_decode($raw, true);
            if (($data['@type'] ?? null) === 'SoftwareApplication') {
                return $data;
            }
        }

        $this->fail('No SoftwareApplication JSON-LD block on the home page');
    }

    /** The offer for one interval, keyed by its billingDuration. */
    private function offer(array $app, string $duration): array
    {
        $offers = $app['offers'] ?? [];
        $this->assertIsList($offers, 'offers must be a list, one Offer per billing interval');

        foreach ($offers as $offer) {
            $durations = array_unique(array_column(array_filter($offer['priceSpecification'] ?? [], fn ($s) => ($s['referenceQuantity']['unitText'] ?? null) === 'form'), 'billingDuration'));
            if ($durations === [$duration]) {
                return $offer;
            }
        }

        $this->fail("No Offer whose per-form prices are billed {$duration}");
    }

    #[DataProvider('configs')]
    public function test_the_schema_states_every_rate_for_both_intervals_from_config(array $override): void
    {
        if ($override) {
            config(['billing.per_form' => array_merge(config('billing.per_form'), $override)]);
        }
        $p = config('billing.per_form');
        $app = $this->softwareApplication();

        $this->assertCount(2, $app['offers'], 'one Offer billed monthly, one billed yearly');

        foreach (['monthly' => 'P1M', 'yearly' => 'P1Y'] as $interval => $duration) {
            $offer = $this->offer($app, $duration);
            $smallest = $interval === 'monthly' ? Bill::monthly($p['min_forms']) : Bill::yearly($p['min_forms']);

            $this->assertSame('Offer', $offer['@type']);
            $this->assertSame('EUR', $offer['priceCurrency']);
            // The Offer's own price is the smallest bill anyone pays, not the price of one form.
            $this->assertSame(self::money($smallest), $offer['price'], "{$interval}: the Offer price is the smallest bill");

            [$full, $lower, $extra] = $offer['priceSpecification'];

            foreach ([$full, $lower, $extra] as $spec) {
                $this->assertSame('UnitPriceSpecification', $spec['@type']);
                $this->assertSame('EUR', $spec['priceCurrency']);
                $this->assertNotEmpty($spec['name'] ?? null);
            }

            $this->assertSame(self::money($p["{$interval}_eur"]), $full['price']);
            $this->assertSame($duration, $full['billingDuration']);
            $this->assertSame(['@type' => 'QuantitativeValue', 'value' => 1, 'unitText' => 'form'], $full['referenceQuantity']);
            $this->assertSame(1, $full['eligibleQuantity']['minValue']);
            $this->assertSame($p['full_price_forms'], $full['eligibleQuantity']['maxValue']);

            $this->assertSame(self::money($p["lower_{$interval}_eur"]), $lower['price']);
            $this->assertSame($duration, $lower['billingDuration']);
            $this->assertSame(['@type' => 'QuantitativeValue', 'value' => 1, 'unitText' => 'form'], $lower['referenceQuantity']);
            $this->assertSame($p['full_price_forms'] + 1, $lower['eligibleQuantity']['minValue']);
            $this->assertArrayNotHasKey('maxValue', $lower['eligibleQuantity']);

            // Extra submissions are charged once a month has ended, on both plans.
            $this->assertSame(self::money($p['extra_eur']), $extra['price']);
            $this->assertSame('P1M', $extra['billingDuration']);
            $this->assertSame($p['submissions'], $extra['referenceQuantity']['value']);

            // The description says the same rates in words, for a reader that does not parse the specs.
            foreach ([Bill::format($p["{$interval}_eur"]), Bill::format($p["lower_{$interval}_eur"]), Bill::format($smallest), Bill::format($p['extra_eur'])] as $figure) {
                $this->assertStringContainsString($figure, $offer['description'], "{$interval}: the description misses {$figure}");
            }
        }
    }

    #[DataProvider('configs')]
    public function test_a_reader_can_work_out_any_bill_from_the_schema_alone(array $override): void
    {
        if ($override) {
            config(['billing.per_form' => array_merge(config('billing.per_form'), $override)]);
        }
        $app = $this->softwareApplication();

        foreach (['monthly' => 'P1M', 'yearly' => 'P1Y'] as $interval => $duration) {
            $offer = $this->offer($app, $duration);
            [$full, $lower] = $offer['priceSpecification'];

            foreach ([1, 3, 10, 25] as $forms) {
                // Sum the tiers as a reader would, never below the Offer's own price (the smallest bill).
                $inFull = min($forms, $full['eligibleQuantity']['maxValue']);
                $sum = $inFull * (float) $full['price'] + ($forms - $inFull) * (float) $lower['price'];
                $read = max($sum, (float) $offer['price']);

                $this->assertEqualsWithDelta(Bill::{$interval}($forms), $read, 0.001, "{$forms} forms {$interval}");
            }
        }
    }
}
