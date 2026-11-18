<?php

declare(strict_types=1);

namespace App\Support\Dates;

use App\Domain\Tenancy\Enums\DateFormat;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Writes dates for people (batch 6, item 1).
 *
 *  - Messages to customers (confirmations, reminders, template variables): the month as a word,
 *    never digits, so no format can be misread: "Monday, 5 January 2027", "… at 10:00".
 *  - Screens and exports: the workspace's format (DD/MM/YYYY by default), in its time zone.
 *
 * Times are stored in UTC; they are only shown in the workspace's time zone.
 */
final class DateText
{
    /** "2027-01-05" → "Tuesday, 5 January 2027"; a date-time also gets " at 10:00". Anything else is returned unchanged. */
    public static function spoken(string $value, ?string $timezone = null): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1) {
            if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return $value;
            }

            return Carbon::createFromDate((int) $m[1], (int) $m[2], (int) $m[3], 'UTC')->format('l, j F Y');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $value) === 1) {
            try {
                return self::spokenAt(Carbon::parse($value), $timezone ?? 'UTC');
            } catch (\Throwable) {
                return $value;
            }
        }

        return $value;
    }

    /** "Monday, 5 January 2027 at 10:00" in the given time zone. */
    public static function spokenAt(DateTimeInterface $at, string $timezone): string
    {
        return Carbon::instance($at)->setTimezone($timezone)->format('l, j F Y \a\t H:i');
    }

    /** A date on a screen or in an export, in the workspace's format: 18/11/2026. */
    public static function short(DateTimeInterface|string $at, DateFormat $format, ?string $timezone = null): string
    {
        $c = is_string($at) ? Carbon::parse($at) : Carbon::instance($at);
        if ($timezone !== null && ! (is_string($at) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $at) === 1)) {
            $c = $c->setTimezone($timezone);
        }

        return $c->format($format->pattern());
    }

    /** A date and time on a screen: 18/11/2026 14:05, in the workspace's time zone. */
    public static function shortAt(DateTimeInterface $at, DateFormat $format, string $timezone): string
    {
        return Carbon::instance($at)->setTimezone($timezone)->format($format->pattern().' H:i');
    }
}
