<?php

declare(strict_types=1);

namespace App\Support\Phone;

/**
 * Checks a phone number typed by a person and turns it into the digits WhatsApp uses (country code
 * plus national number, no "+"). A number with "+" or "00" must be a valid international number; a
 * number without it is read in the workspace's country first when it starts with the trunk prefix
 * ("0333 1370070" in Pakistan → 923331370070), and otherwise as an international number written
 * without the "+" (971501234567), then in the workspace's country (501234567 in the UAE).
 *
 * Validity follows libphonenumber's per-country rules (regions.php): the national number must have
 * a possible length and match the country's number pattern.
 */
final class PhoneNumbers
{
    /** @var array<string, array{0: int, 1: string, 2: string, 3: list<int>}>|null */
    private static ?array $regions = null;

    /** @var array<int, list<string>>|null calling code → regions */
    private static ?array $byCode = null;

    /** The digits for WhatsApp, or null when the number is not valid. */
    public static function normalize(?string $input, ?string $country = null): ?string
    {
        return self::parse($input, $country)['digits'];
    }

    /**
     * @return array{digits: ?string, error: ?string}
     */
    public static function parse(?string $input, ?string $country = null): array
    {
        $raw = trim((string) $input);
        if ($raw === '') {
            return ['digits' => null, 'error' => 'Enter a phone number.'];
        }
        if (preg_match('/[^\d\s+().\-\/]/u', $raw) === 1) {
            return ['digits' => null, 'error' => 'A phone number can only contain digits, spaces, + ( ) - and /.'];
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        $international = str_starts_with($raw, '+') || str_starts_with($raw, '00');
        if ($international) {
            $digits = str_starts_with($raw, '00') ? substr($digits, 2) : $digits;
            if (str_contains(substr($raw, 1), '+')) {
                return ['digits' => null, 'error' => 'Use one “+”, at the start of the number.'];
            }

            return self::international($digits) !== null
                ? ['digits' => self::international($digits), 'error' => null]
                : ['digits' => null, 'error' => self::invalid($country)];
        }

        $country = $country !== null && isset(self::regions()[strtoupper($country)]) ? strtoupper($country) : null;
        $national = $country !== null ? self::national($digits, $country) : null;
        $asInternational = self::international($digits);
        $prefix = $country !== null ? self::regions()[$country][1] : '';
        // A leading trunk prefix ("0…") means a local number; otherwise a number with its country code wins.
        $found = $prefix !== '' && str_starts_with($digits, $prefix) ? ($national ?? $asInternational) : ($asInternational ?? $national);

        return $found !== null ? ['digits' => $found, 'error' => null] : ['digits' => null, 'error' => self::invalid($country)];
    }

    /** Whether these digits (country code first) are a valid number. */
    public static function valid(string $digits): bool
    {
        return self::international($digits) !== null;
    }

    private static function international(string $digits): ?string
    {
        if (strlen($digits) < 7 || strlen($digits) > 15 || str_starts_with($digits, '0')) {
            return null;
        }
        for ($len = 1; $len <= 3; $len++) {
            $code = (int) substr($digits, 0, $len);
            foreach (self::byCode()[$code] ?? [] as $region) {
                if (self::matches(substr($digits, $len), $region)) {
                    return $digits;
                }
            }
        }

        return null;
    }

    private static function national(string $digits, string $region): ?string
    {
        [$code, $prefix] = self::regions()[$region];
        $candidates = [$digits];
        if ($prefix !== '' && str_starts_with($digits, $prefix)) {
            array_unshift($candidates, substr($digits, strlen($prefix)));
        }
        foreach ($candidates as $n) {
            if ($n !== '' && self::matches($n, $region)) {
                return $code.$n;
            }
        }

        return null;
    }

    private static function matches(string $national, string $region): bool
    {
        [, , $pattern, $lengths] = self::regions()[$region];
        if ($lengths !== [] && ! in_array(strlen($national), $lengths, true)) {
            return false;
        }

        return preg_match('/^(?:'.$pattern.')$/', $national) === 1;
    }

    private static function invalid(?string $country): string
    {
        $example = match ($country) {
            'PK' => '+92 300 1234567',
            'SA' => '+966 50 123 4567',
            'GB' => '+44 7700 900123',
            'IN' => '+91 98765 43210',
            default => '+971 50 123 4567',
        };

        return "This is not a valid phone number. Include the country code, for example {$example}.";
    }

    /** @return array<string, array{0: int, 1: string, 2: string, 3: list<int>}> */
    private static function regions(): array
    {
        return self::$regions ??= require __DIR__.'/regions.php';
    }

    /** @return array<int, list<string>> */
    private static function byCode(): array
    {
        if (self::$byCode === null) {
            self::$byCode = [];
            foreach (self::regions() as $region => [$code]) {
                self::$byCode[$code][] = $region;
            }
        }

        return self::$byCode;
    }
}
