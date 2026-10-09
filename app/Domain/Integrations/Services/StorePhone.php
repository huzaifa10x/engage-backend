<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Services;

use App\Domain\Messaging\Models\Contact;

/**
 * Store customers often type their number the local way (050 123 4567). WhatsApp needs the
 * international form, so a number without a country code gets one: from the order's country
 * when the store sent it, otherwise from the default chosen for the integration.
 */
final class StorePhone
{
    /** ISO country → calling code, for the countries stores most often ship to. */
    private const CALLING_CODES = [
        'AE' => '971', 'SA' => '966', 'QA' => '974', 'KW' => '965', 'BH' => '973', 'OM' => '968', 'PK' => '92', 'IN' => '91', 'EG' => '20', 'JO' => '962',
        'LB' => '961', 'IQ' => '964', 'TR' => '90', 'GB' => '44', 'US' => '1', 'CA' => '1', 'AU' => '61', 'NZ' => '64', 'DE' => '49', 'FR' => '33',
        'IT' => '39', 'ES' => '34', 'NL' => '31', 'BE' => '32', 'CH' => '41', 'AT' => '43', 'SE' => '46', 'NO' => '47', 'DK' => '45', 'IE' => '353',
        'PT' => '351', 'PL' => '48', 'GR' => '30', 'RU' => '7', 'CN' => '86', 'JP' => '81', 'KR' => '82', 'SG' => '65', 'MY' => '60', 'ID' => '62',
        'PH' => '63', 'TH' => '66', 'VN' => '84', 'BD' => '880', 'LK' => '94', 'NP' => '977', 'ZA' => '27', 'NG' => '234', 'KE' => '254', 'MA' => '212',
        'TN' => '216', 'DZ' => '213', 'BR' => '55', 'MX' => '52', 'AR' => '54', 'CO' => '57', 'CL' => '56', 'HK' => '852', 'IL' => '972',
    ];

    /** @return ?string digits only, with country code (the form WhatsApp uses), or null when it cannot be worked out */
    public static function normalize(?string $raw, ?string $countryIso = null, ?string $defaultCallingCode = null): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (str_starts_with($raw, '+') || str_starts_with($raw, '00')) {
            return Contact::normalizePhone($raw);
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        $code = self::CALLING_CODES[strtoupper((string) $countryIso)] ?? preg_replace('/\D+/', '', (string) $defaultCallingCode);
        if ($code === '' || $code === null) {
            // No way to know the country: accept only what is long enough to already include a country code.
            return strlen($digits) >= 11 ? Contact::normalizePhone($digits) : null;
        }
        if (str_starts_with($digits, '0')) {
            return Contact::normalizePhone($code.ltrim($digits, '0'));
        }
        // Already written with the country code but without the plus (971501234567).
        if (str_starts_with($digits, $code) && strlen($digits) >= strlen($code) + 8) {
            return Contact::normalizePhone($digits);
        }

        return Contact::normalizePhone($code.$digits);
    }
}
