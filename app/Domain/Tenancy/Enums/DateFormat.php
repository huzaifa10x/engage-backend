<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Enums;

/**
 * How a workspace writes and reads dates (batch 6, item 1). Separate from the time zone: one is
 * never worked out from the other. Day-first is the default, not a hard-coded rule.
 */
enum DateFormat: string
{
    case DayMonthYear = 'dmy';   // DD/MM/YYYY (default)
    case MonthDayYear = 'mdy';   // MM/DD/YYYY
    case YearMonthDay = 'ymd';   // YYYY-MM-DD

    /** Countries that write the month first, or the year first; every other country is day-first. */
    private const MONTH_FIRST = ['US', 'PR', 'GU', 'AS', 'MP', 'UM', 'VI', 'FM', 'MH', 'PW', 'PH', 'BZ'];

    private const YEAR_FIRST = ['CN', 'JP', 'KR', 'KP', 'TW', 'HU', 'LT', 'MN', 'IR'];

    public static function forCountry(?string $country): self
    {
        $country = strtoupper((string) $country);

        return match (true) {
            in_array($country, self::MONTH_FIRST, true) => self::MonthDayYear,
            in_array($country, self::YEAR_FIRST, true) => self::YearMonthDay,
            default => self::DayMonthYear,
        };
    }

    /** PHP date() pattern for a date in this format. */
    public function pattern(): string
    {
        return match ($this) {
            self::DayMonthYear => 'd/m/Y',
            self::MonthDayYear => 'm/d/Y',
            self::YearMonthDay => 'Y-m-d',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::DayMonthYear => 'DD/MM/YYYY',
            self::MonthDayYear => 'MM/DD/YYYY',
            self::YearMonthDay => 'YYYY-MM-DD',
        };
    }

    /** A typed example customers are shown when a date is refused ("for example 18/11"). */
    public function example(): string
    {
        return match ($this) {
            self::DayMonthYear => '18/11',
            self::MonthDayYear => '11/18',
            self::YearMonthDay => '2026-11-18',
        };
    }
}
