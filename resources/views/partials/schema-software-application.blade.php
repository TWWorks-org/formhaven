{{-- SoftwareApplication schema: homepage only. Prices come from config/billing.php and App\Support\Bill,
     the same rates as the rate card on /pricing. Only add an aggregateRating block with REAL third-party ratings. --}}
{{-- The array is built in the raw PHP block below on purpose: outside it, Blade compiles the at-sign context key as its own context directive, even inside an echo, and the JSON breaks. --}}
@php
    $perForm = config('billing.per_form');
    $money = fn (float $eur) => number_format($eur, 2, '.', '');
    $perUnit = fn (int|float $value, string $unit) => ['@type' => 'QuantitativeValue', 'value' => $value, 'unitText' => $unit];
    // One Offer per billing interval. Offer.price is the smallest bill anyone pays (min_forms forms' worth);
    // the specs give the graduated rates: the first full_price_forms forms at one price, every form after at the lower one.
    $offer = function (string $interval) use ($perForm, $money, $perUnit) {
        $duration = $interval === 'monthly' ? 'P1M' : 'P1Y';
        $period = $interval === 'monthly' ? 'a month' : 'a year';
        $full = $perForm["{$interval}_eur"];
        $lower = $perForm["lower_{$interval}_eur"];
        $smallest = \App\Support\Bill::{$interval}($perForm['min_forms']);
        $f = [\App\Support\Bill::class, 'format'];
        $extra = 'Each started '.number_format($perForm['submissions']).' submissions past the pool';

        return [
            '@type' => 'Offer',
            'name' => 'Billed '.$interval,
            'description' => 'Billed '.$interval.'. Forms 1 to '.$perForm['full_price_forms'].': '.$f($full).' per form '.$period
                .'. Form '.($perForm['full_price_forms'] + 1).' and every form after it: '.$f($lower).' per form '.$period
                .'. Smallest bill: '.$f($smallest).' '.$period.', for at least '.$perForm['min_forms'].' forms'
                .'. Each form includes '.number_format($perForm['submissions']).' submissions a month'
                .'. '.$extra.': '.$f($perForm['extra_eur']).', charged once the month has ended.',
            'priceCurrency' => 'EUR',
            'price' => $money($smallest),
            'priceSpecification' => [
                [
                    '@type' => 'UnitPriceSpecification',
                    'name' => 'Forms 1 to '.$perForm['full_price_forms'],
                    'price' => $money($full),
                    'priceCurrency' => 'EUR',
                    'referenceQuantity' => $perUnit(1, 'form'),
                    'billingDuration' => $duration,
                    'eligibleQuantity' => ['@type' => 'QuantitativeValue', 'minValue' => 1, 'maxValue' => $perForm['full_price_forms'], 'unitText' => 'forms'],
                ],
                [
                    '@type' => 'UnitPriceSpecification',
                    'name' => 'Form '.($perForm['full_price_forms'] + 1).' and every form after it',
                    'price' => $money($lower),
                    'priceCurrency' => 'EUR',
                    'referenceQuantity' => $perUnit(1, 'form'),
                    'billingDuration' => $duration,
                    'eligibleQuantity' => ['@type' => 'QuantitativeValue', 'minValue' => $perForm['full_price_forms'] + 1, 'unitText' => 'forms'],
                ],
                [
                    '@type' => 'UnitPriceSpecification',
                    'name' => $extra,
                    'price' => $money($perForm['extra_eur']),
                    'priceCurrency' => 'EUR',
                    'referenceQuantity' => $perUnit($perForm['submissions'], 'submissions'),
                    // Charged per month that has ended, on a yearly plan too.
                    'billingDuration' => 'P1M',
                ],
            ],
        ];
    };
    $schema = [
    '@context' => 'https://schema.org',
    '@type' => 'SoftwareApplication',
    'name' => config('app.name'),
    'applicationCategory' => 'DeveloperApplication',
    'operatingSystem' => 'Web',
    'description' => 'EU form backend an AI agent can set up end to end. Messages are stored in Germany, filtered for spam and emailed to you, with a DPA and the privacy text for your site.',
    'url' => config('app.url'),
    'offers' => [$offer('monthly'), $offer('yearly')],
];
@endphp
<script type="application/ld+json">
{!! json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>
