<?php

declare(strict_types=1);

namespace App\Domain\Identity\Security;

/** A small, dependency-free reading of a User-Agent header: browser, operating system, device type. */
final class UserAgent
{
    /** @return array{browser: string, os: string, device: string} */
    public static function parse(?string $ua): array
    {
        $ua = (string) $ua;

        $browser = match (true) {
            $ua === '' => 'Unknown',
            (bool) preg_match('/Edg(e|A|iOS)?\/([\d.]+)/', $ua, $m) => 'Edge '.self::major($m[2]),
            (bool) preg_match('/OPR\/([\d.]+)/', $ua, $m) => 'Opera '.self::major($m[1]),
            (bool) preg_match('/SamsungBrowser\/([\d.]+)/', $ua, $m) => 'Samsung Internet '.self::major($m[1]),
            (bool) preg_match('/(Firefox|FxiOS)\/([\d.]+)/', $ua, $m) => 'Firefox '.self::major($m[2]),
            (bool) preg_match('/(Chrome|CriOS)\/([\d.]+)/', $ua, $m) => 'Chrome '.self::major($m[2]),
            (bool) preg_match('/Version\/([\d.]+).*Safari/', $ua, $m) => 'Safari '.self::major($m[1]),
            str_contains($ua, 'Safari') => 'Safari',
            default => 'Other',
        };

        $os = match (true) {
            (bool) preg_match('/Windows NT 10/', $ua) => 'Windows 10/11',
            str_contains($ua, 'Windows') => 'Windows',
            (bool) preg_match('/(iPhone|iPad|iPod)/', $ua) => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Unknown',
        };

        $device = match (true) {
            (bool) preg_match('/iPad|Tablet|(Android(?!.*Mobile))/', $ua) => 'Tablet',
            (bool) preg_match('/Mobi|iPhone|iPod|Android/', $ua) => 'Mobile',
            default => 'Desktop',
        };

        return ['browser' => $browser, 'os' => $os, 'device' => $device];
    }

    private static function major(string $version): string
    {
        return explode('.', $version)[0];
    }
}
