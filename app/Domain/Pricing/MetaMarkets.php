<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * Meta prices WhatsApp messages by the recipient's country code, grouped in "markets": some
 * countries have their own rate, the others share a regional one ("Rest of Middle East"), and
 * every country not listed is "Other". The names match the market column of Meta's rate card.
 */
final class MetaMarkets
{
    public const OTHER = 'Other';

    /** Countries with a rate of their own (ISO 3166 alpha-2 → market). */
    private const OWN = [
        'AR' => 'Argentina', 'BR' => 'Brazil', 'CL' => 'Chile', 'CO' => 'Colombia', 'EG' => 'Egypt', 'FR' => 'France',
        'DE' => 'Germany', 'HK' => 'Hong Kong', 'IN' => 'India', 'ID' => 'Indonesia', 'IL' => 'Israel', 'IT' => 'Italy',
        'KW' => 'Kuwait', 'MY' => 'Malaysia', 'MX' => 'Mexico', 'NL' => 'Netherlands', 'NG' => 'Nigeria', 'OM' => 'Oman',
        'PK' => 'Pakistan', 'PE' => 'Peru', 'PL' => 'Poland', 'QA' => 'Qatar', 'RU' => 'Russia', 'SA' => 'Saudi Arabia',
        'SG' => 'Singapore', 'ZA' => 'South Africa', 'ES' => 'Spain', 'TR' => 'Turkey', 'AE' => 'United Arab Emirates',
        'GB' => 'United Kingdom', 'US' => 'North America', 'CA' => 'North America',
    ];

    /** Regional markets (market → countries). */
    private const REGIONS = [
        'Rest of Africa' => ['DZ', 'AO', 'BJ', 'BW', 'BF', 'BI', 'CM', 'TD', 'CG', 'CD', 'ER', 'ET', 'GA', 'GM', 'GH', 'GW', 'CI', 'KE', 'LS', 'LR',
            'LY', 'MG', 'MW', 'ML', 'MR', 'MA', 'MZ', 'NA', 'NE', 'RW', 'SN', 'SL', 'SO', 'SS', 'SD', 'SZ', 'TZ', 'TG', 'TN', 'UG', 'ZM'],
        'Rest of Asia Pacific' => ['AF', 'AU', 'BD', 'KH', 'CN', 'JP', 'LA', 'MN', 'NP', 'NZ', 'PG', 'PH', 'LK', 'TW', 'TJ', 'TH', 'TM', 'UZ', 'VN'],
        'Rest of Central & Eastern Europe' => ['AL', 'AM', 'AZ', 'BY', 'BG', 'HR', 'CZ', 'GE', 'GR', 'HU', 'LV', 'LT', 'MK', 'MD', 'RO', 'RS', 'SK', 'SI', 'UA'],
        'Rest of Latin America' => ['BO', 'CR', 'DO', 'EC', 'SV', 'GT', 'HT', 'HN', 'JM', 'NI', 'PA', 'PY', 'PR', 'UY', 'VE'],
        'Rest of Middle East' => ['BH', 'IQ', 'JO', 'LB', 'YE'],
        'Rest of Western Europe' => ['AT', 'BE', 'DK', 'FI', 'IE', 'NO', 'PT', 'SE', 'CH'],
    ];

    public static function forCountry(?string $country): string
    {
        $country = strtoupper((string) $country);
        if (isset(self::OWN[$country])) {
            return self::OWN[$country];
        }
        foreach (self::REGIONS as $market => $countries) {
            if (in_array($country, $countries, true)) {
                return $market;
            }
        }

        return self::OTHER;
    }

    /** @return list<string> the countries of a market (empty for "Other": everything not listed) */
    public static function countries(string $market): array
    {
        return array_merge(array_keys(array_filter(self::OWN, fn (string $m) => $m === $market)), self::REGIONS[$market] ?? []);
    }
}
