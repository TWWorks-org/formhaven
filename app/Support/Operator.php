<?php

namespace App\Support;

/**
 * Facts about who runs this install and where it stores messages, read from config/legal.php,
 * which reads them from env with no default. Copy that says where messages are stored asks
 * here, so an install that set nothing never claims a country.
 */
final class Operator
{
    /** EU member states, by the English name LEGAL_HOSTING_PLACE_EN ends with. */
    private const EU = [
        'Austria', 'Belgium', 'Bulgaria', 'Croatia', 'Cyprus', 'Czechia', 'Czech Republic', 'Denmark', 'Estonia',
        'Finland', 'France', 'Germany', 'Greece', 'Hungary', 'Ireland', 'Italy', 'Latvia', 'Lithuania', 'Luxembourg',
        'Malta', 'the Netherlands', 'Netherlands', 'Poland', 'Portugal', 'Romania', 'Slovakia', 'Slovenia', 'Spain', 'Sweden',
    ];

    /** The country messages are stored in: the last part of LEGAL_HOSTING_PLACE_EN ("Place, Country"), or null. */
    public static function storageCountry(): ?string
    {
        $place = trim((string) config('legal.hosting.place.en'));

        if ($place === '') {
            return null;
        }

        $parts = explode(',', $place);

        return trim(end($parts)) ?: null;
    }

    /** True only when the configured storage country is an EU member state. */
    public static function storedInEu(): bool
    {
        return in_array(self::storageCountry(), self::EU, true);
    }
}
