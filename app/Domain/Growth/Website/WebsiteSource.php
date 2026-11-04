<?php

declare(strict_types=1);

namespace App\Domain\Growth\Website;

/**
 * Where a website tap came from, from the campaign details the tracker sent (Technical Specification
 * v1.2 §6, Marketing Analytics Specification §4). Only permitted evidence: UTMs, Google click IDs,
 * fbclid and the host of the page that sent the visitor. The campaign name only ever comes from
 * utm_campaign — never worked out from gclid (that needs Google Ads access, R3).
 *
 *  google_ads      gclid / gbraid / wbraid, or utm_source=google with a paid medium (cpc, ppc, paid…)
 *  meta_ad         utm_source = ig / fb / instagram / facebook with a paid medium, or Meta's
 *                  {{site_source_name}} values (ig, fb, msg, an) — Instagram/Facebook proven by utm_source
 *  paid_other      any other utm_source with a paid medium (cpc, ppc, paid…)
 *  google_organic  no ad parameters, the visitor came from a Google search page
 *  organic         other search engines and social sites without ad parameters, or utm_medium=organic
 *  referral        another website linked to it
 *  direct          nothing to go on (typed the address, a bookmark, an app)
 *
 * Paid ones are leads (Growth Hub › Leads); the others are recorded and counted in Analytics ›
 * Marketing only.
 */
final class WebsiteSource
{
    public const CAMPAIGN_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'gbraid', 'wbraid', 'fbclid'];

    public const CLICK_ID_KEYS = ['gclid', 'gbraid', 'wbraid', 'fbclid'];

    private const PAID_MEDIUMS = ['cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'paid-search', 'paidsocial', 'paid_social', 'paid-social', 'cpm', 'display', 'sem', 'ads', 'ad', 'banner', 'retargeting', 'remarketing', 'pmax'];

    private const META_SOURCES = ['ig' => 'instagram', 'instagram' => 'instagram', 'fb' => 'facebook', 'facebook' => 'facebook', 'msg' => null, 'an' => null, 'meta' => null];

    private const SEARCH = ['bing.', 'duckduckgo.', 'yahoo.', 'yandex.', 'baidu.', 'ecosia.', 'search.brave.', 'naver.'];

    private const SOCIAL = ['facebook.', 'fb.', 'instagram.', 'l.instagram.', 't.co', 'twitter.', 'x.com', 'linkedin.', 'lnkd.in', 'tiktok.', 'youtube.', 'snapchat.', 'pinterest.', 'reddit.'];

    /**
     * @param  array<string, mixed>  $campaign
     * @return array{source: string, label: string, campaign: ?string, platform: ?string, platform_proof: ?string, paid: bool}
     */
    public static function classify(array $campaign, ?string $referrerHost, ?string $ownHost = null): array
    {
        $get = fn (string $k) => isset($campaign[$k]) && is_string($campaign[$k]) && $campaign[$k] !== '' ? $campaign[$k] : null;
        $source = mb_strtolower((string) $get('utm_source'));
        $medium = mb_strtolower((string) $get('utm_medium'));
        $name = $get('utm_campaign');
        $paidMedium = $medium !== '' && in_array($medium, self::PAID_MEDIUMS, true);
        $host = $referrerHost !== null ? mb_strtolower($referrerHost) : null;
        if ($host !== null && $ownHost !== null && self::sameSite($host, $ownHost)) {
            $host = null; // the site's own pages are not a source
        }

        if ($get('gclid') !== null || $get('gbraid') !== null || $get('wbraid') !== null || ($source === 'google' && $paidMedium)) {
            return self::out('google_ads', 'Google Ads', $name, paid: true);
        }
        if (array_key_exists($source, self::META_SOURCES) && ($paidMedium || $medium === '' || in_array($source, ['ig', 'fb', 'msg', 'an'], true))) {
            $platform = self::META_SOURCES[$source];
            $label = match ($platform) {
                'instagram' => 'Meta ad · Instagram',
                'facebook' => 'Meta ad · Facebook',
                default => 'Meta ad',
            };

            return self::out('meta_ad', $label, $name, $platform, $platform !== null ? 'utm_source' : null, true);
        }
        if ($source !== '' && $paidMedium) {
            return self::out('paid_other', 'Paid campaign · '.mb_substr((string) $get('utm_source'), 0, 40), $name, paid: true);
        }
        if ($source !== '') {
            $label = $medium === 'organic' || $medium === 'social' ? 'Organic · '.mb_substr((string) $get('utm_source'), 0, 40) : 'Campaign · '.mb_substr((string) $get('utm_source'), 0, 40);

            return self::out($medium === 'email' ? 'referral' : 'organic', $label, $name);
        }
        if ($host !== null && preg_match('/(^|\.)google\.[a-z.]+$/', $host) === 1) {
            return self::out('google_organic', 'Google organic', null);
        }
        if ($host !== null && self::matches($host, self::SEARCH)) {
            return self::out('organic', 'Organic search', null);
        }
        if ($host !== null && self::matches($host, self::SOCIAL)) {
            return self::out('organic', 'Social', null);
        }
        if ($host !== null) {
            return self::out('referral', 'Referral · '.mb_substr($host, 0, 60), null);
        }

        return self::out('direct', 'Direct', null);
    }

    /**
     * The tracker's campaign object, reduced to the allowed keys with length and character limits.
     *
     * @return array<string, string>
     */
    public static function sanitize(mixed $campaign): array
    {
        $out = [];
        if (! is_array($campaign)) {
            return $out;
        }
        foreach (self::CAMPAIGN_KEYS as $key) {
            $v = $campaign[$key] ?? null;
            if (! is_string($v) || $v === '') {
                continue;
            }
            $max = in_array($key, self::CLICK_ID_KEYS, true) ? 512 : 200;
            $v = trim((string) preg_replace('/[\x00-\x1F\x7F<>"`]/u', '', mb_substr($v, 0, $max)));
            if ($v !== '') {
                $out[$key] = $v;
            }
        }

        return $out;
    }

    /** Lower-case host name only, or null. */
    public static function host(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 253) {
            return null;
        }
        $h = mb_strtolower(trim($value));

        return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $h) === 1 ? $h : null;
    }

    public static function sameSite(string $a, string $b): bool
    {
        $strip = fn (string $h) => preg_replace('/^www\./', '', mb_strtolower($h));

        return $strip($a) === $strip($b);
    }

    /** @param list<string> $list */
    private static function matches(string $host, array $list): bool
    {
        foreach ($list as $needle) {
            if (str_starts_with($host, $needle) || str_contains($host, '.'.$needle) || $host === rtrim($needle, '.')) {
                return true;
            }
        }

        return false;
    }

    /** @return array{source: string, label: string, campaign: ?string, platform: ?string, platform_proof: ?string, paid: bool} */
    private static function out(string $source, string $label, ?string $campaign, ?string $platform = null, ?string $proof = null, bool $paid = false): array
    {
        return ['source' => $source, 'label' => $label, 'campaign' => $campaign !== null ? mb_substr($campaign, 0, 100) : null, 'platform' => $platform, 'platform_proof' => $proof, 'paid' => $paid];
    }
}
