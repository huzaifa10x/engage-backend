<?php

declare(strict_types=1);

namespace App\Support\Dates;

use App\Domain\Tenancy\Enums\DateFormat;
use Illuminate\Support\Carbon;

/**
 * Reads a date a customer typed, in the workspace's date format (batch 6, item 1). Used by both
 * chatbot engines and forms; never guesses.
 *
 * Accepted in every format: YYYY-MM-DD, "18 Nov", "Nov 18", "18 November 2026", "today", "tomorrow"
 * (Arabic digits too). Numbers with / . or - are read in the workspace's order: 5/1 is 5 January in
 * a DD/MM workspace and May 1 in an MM/DD one; a YYYY-MM-DD workspace accepts only the year first.
 * A day and month without a year that has already passed this year means next year.
 *
 * Refused, with a reason: dates that don't exist (31/4, 29/2 in a non-leap year) → `impossible`;
 * the other order (11/18 in DD/MM, 18/11 in MM/DD) or a short date in a YYYY-MM-DD workspace →
 * `wrong_order`; anything else → `not_a_date`.
 */
final class DateReader
{
    private const ARABIC_DIGITS = ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'];

    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    /** @return array{0: ?string, 1: ?string} [Y-m-d, null] or [null, reason] */
    public static function read(string $text, Carbon $today, DateFormat $format = DateFormat::DayMonthYear): array
    {
        $t = mb_strtolower(trim(strtr($text, self::ARABIC_DIGITS)));
        if ($t === 'today') {
            return [$today->toDateString(), null];
        }
        if ($t === 'tomorrow') {
            return [$today->copy()->addDay()->toDateString(), null];
        }
        $month = fn (string $name): ?int => self::MONTHS[substr($name, 0, 3)] ?? null;
        $y = null;
        if (preg_match('#^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$#', $t, $m) === 1) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})(?:[/.\-](\d{2}|\d{4}))?$#', $t, $m) === 1) {
            if ($format === DateFormat::YearMonthDay) {
                return [null, 'wrong_order'];
            }
            [$first, $second] = [(int) $m[1], (int) $m[2]];
            [$d, $mo] = $format === DateFormat::MonthDayYear ? [$second, $first] : [$first, $second];
            // Written the other way round (the "month" can't be a month, the "day" could be one).
            if ($mo > 12 && $d <= 12) {
                return [null, 'wrong_order'];
            }
            if (($m[3] ?? '') !== '') {
                $y = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
            }
        } elseif (preg_match('/^(\d{1,2})(?:st|nd|rd|th)?\s*([a-z]{3,9})\.?(?:,?\s+(\d{4}))?$/', $t, $m) === 1 && $month($m[2]) !== null) {
            [$d, $mo] = [(int) $m[1], (int) $month($m[2])];
            $y = ($m[3] ?? '') !== '' ? (int) $m[3] : null;
        } elseif (preg_match('/^([a-z]{3,9})\.?\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s+(\d{4}))?$/', $t, $m) === 1 && $month($m[1]) !== null) {
            [$d, $mo] = [(int) $m[2], (int) $month($m[1])];
            $y = ($m[3] ?? '') !== '' ? (int) $m[3] : null;
        } else {
            return [null, 'not_a_date'];
        }
        if ($y === null) {
            $y = (int) $today->year;
            if (checkdate($mo, $d, $y) && sprintf('%04d-%02d-%02d', $y, $mo, $d) < $today->toDateString()) {
                $y++;
            }
        }
        if (! checkdate($mo, $d, $y)) {
            return [null, 'impossible'];
        }

        return [sprintf('%04d-%02d-%02d', $y, $mo, $d), null];
    }

    /** What the customer is told when a date was refused for its order or format. */
    public static function orderHint(DateFormat $format): string
    {
        return match ($format) {
            DateFormat::DayMonthYear => 'Sorry, please write the day first, for example 18/11.',
            DateFormat::MonthDayYear => 'Sorry, please write the month first, for example 11/18.',
            DateFormat::YearMonthDay => 'Sorry, please write the date as year-month-day, for example 2026-11-18.',
        };
    }
}
