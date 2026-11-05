<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * Opening hours (WhatsApp Engagement Plan v4 §III.1): weekly hours per workspace, optionally
 * different hours per WhatsApp number, weekend days by switching days off (Saturday–Sunday,
 * Friday only, Friday–Saturday…), and a list of holiday dates (Eid, National Day). Time zone: the
 * workspace's, Asia/Dubai when none is set.
 *
 * Business hours switched off = always open (nothing is "after hours"), and response times are
 * then measured in plain time.
 */
final class BusinessHours
{
    public const DEFAULT_TIMEZONE = 'Asia/Dubai';

    private const SCAN_DAYS = 21;

    public static function timezone(?Tenant $tenant): string
    {
        $tz = $tenant?->timezone;

        return is_string($tz) && $tz !== '' ? $tz : self::DEFAULT_TIMEZONE;
    }

    /**
     * The week for a number: its own hours when set, otherwise the workspace's.
     *
     * @return array<string, array{open: bool, from: string, to: string}>
     */
    public static function week(?Tenant $tenant, ?string $numberId = null): array
    {
        $settings = InboxTools::settings($tenant);
        if ($numberId !== null && isset($settings['number_hours'][$numberId])) {
            return $settings['number_hours'][$numberId];
        }

        return $settings['business_hours']['days'];
    }

    public static function enabled(?Tenant $tenant): bool
    {
        return InboxTools::settings($tenant)['business_hours']['enabled'];
    }

    /** @return list<string> Y-m-d */
    public static function holidays(?Tenant $tenant): array
    {
        return array_column(InboxTools::settings($tenant)['holidays'], 'date');
    }

    public static function isOpen(?Tenant $tenant, ?string $numberId = null, ?Carbon $at = null): bool
    {
        if (! self::enabled($tenant)) {
            return true;
        }
        $local = ($at ?? now())->copy()->setTimezone(self::timezone($tenant));
        [$from, $to] = self::interval($tenant, $numberId, $local) ?? [null, null];

        return $from !== null && $local->gte($from) && $local->lt($to);
    }

    /** The next time the team is open (now when open); null when hours are off or no day is open. */
    public static function nextOpen(?Tenant $tenant, ?string $numberId = null, ?Carbon $at = null): ?Carbon
    {
        if (! self::enabled($tenant)) {
            return null;
        }
        $local = ($at ?? now())->copy()->setTimezone(self::timezone($tenant));
        for ($i = 0; $i < self::SCAN_DAYS; $i++) {
            $day = $local->copy()->startOfDay()->addDays($i);
            $span = self::interval($tenant, $numberId, $day);
            if ($span === null) {
                continue;
            }
            if ($i === 0 && $local->lt($span[1])) {
                return $local->gte($span[0]) ? $local : $span[0];
            }
            if ($i > 0) {
                return $span[0];
            }
        }

        return null;
    }

    /** "today at 9:00", "tomorrow at 9:00", "Sunday at 9:00", "Sunday 12 April at 9:00" — for the after-hours reply. */
    public static function nextOpenLabel(?Tenant $tenant, ?string $numberId = null, ?Carbon $at = null): string
    {
        $next = self::nextOpen($tenant, $numberId, $at);
        if ($next === null) {
            return 'soon';
        }
        $now = ($at ?? now())->copy()->setTimezone(self::timezone($tenant));
        $days = (int) $now->copy()->startOfDay()->diffInDays($next->copy()->startOfDay());
        $time = $next->format('G:i');

        return match (true) {
            $days === 0 => "today at {$time}",
            $days === 1 => "tomorrow at {$time}",
            $days < 7 => $next->format('l')." at {$time}",
            default => $next->format('l j F')." at {$time}",
        };
    }

    /**
     * Seconds between two moments that fall inside opening hours (holidays and closed days excluded).
     * Plain seconds when business hours are off.
     */
    public static function seconds(?Tenant $tenant, ?string $numberId, Carbon $from, Carbon $to): int
    {
        if ($to->lte($from)) {
            return 0;
        }
        if (! self::enabled($tenant)) {
            return (int) $from->diffInSeconds($to, true);
        }
        $tz = self::timezone($tenant);
        $a = $from->copy()->setTimezone($tz);
        $b = $to->copy()->setTimezone($tz);
        $total = 0;
        for ($day = $a->copy()->startOfDay(), $i = 0; $day->lte($b) && $i < 400; $day->addDay(), $i++) {
            $span = self::interval($tenant, $numberId, $day);
            if ($span === null) {
                continue;
            }
            $start = $span[0]->max($a);
            $end = $span[1]->min($b);
            if ($end->gt($start)) {
                $total += (int) $start->diffInSeconds($end, true);
            }
        }

        return $total;
    }

    /**
     * That day's opening interval in local time, or null when closed all day (day off, holiday).
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private static function interval(?Tenant $tenant, ?string $numberId, Carbon $local): ?array
    {
        if (in_array($local->toDateString(), self::holidays($tenant), true)) {
            return null;
        }
        $d = self::week($tenant, $numberId)[InboxTools::DAYS[$local->dayOfWeekIso - 1]] ?? null;
        if ($d === null || ! $d['open'] || $d['from'] >= $d['to']) {
            return null;
        }
        [$fh, $fm] = array_map('intval', explode(':', $d['from']));
        [$th, $tm] = array_map('intval', explode(':', $d['to']));

        return [$local->copy()->setTime($fh, $fm), $local->copy()->setTime($th, $tm)];
    }
}
