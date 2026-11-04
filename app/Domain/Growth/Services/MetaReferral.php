<?php

declare(strict_types=1);

namespace App\Domain\Growth\Services;

/**
 * messages[].referral of an inbound WhatsApp webhook: the click-to-WhatsApp ad (or post) that
 * started the chat. Every field is optional and varies between ads, so only fields that are
 * present are kept (Technical Specification v1.2 §2). source_id is Meta's ad/source ID, not a
 * campaign ID. The referral has no platform field: platform stays null unless proven elsewhere
 * (Blueprint v1.4), and the lead shows plain "Meta ad".
 */
final class MetaReferral
{
    /**
     * Every field Meta sends on a Click-to-WhatsApp referral, with its maximum stored length:
     * source_type (ad | post), source_id (the ad ID), source_url (ad or post link), headline/body
     * (the ad text the person saw), media_type with image_url / video_url / thumbnail_url (the ad
     * creative), ctwa_clid (the click ID for this tap — kept private, never shown to agents).
     */
    public const FIELDS = [
        'source_id' => 64, 'source_type' => 20, 'source_url' => 2048, 'headline' => 512,
        'body' => 2048, 'ctwa_clid' => 512, 'media_type' => 20,
        'image_url' => 2048, 'video_url' => 2048, 'thumbnail_url' => 2048,
    ];

    /** Instagram hosts: a source_url on one of these proves the ad was seen on Instagram. */
    private const INSTAGRAM_HOSTS = ['instagram.com', 'www.instagram.com', 'l.instagram.com', 'ig.me'];

    /**
     * Platform proven by the ad link itself, or null. Only an unmistakable Instagram link counts;
     * fb.me short links are used for ads on both platforms and prove nothing.
     *
     * @return array{platform: string, proof: string}|null
     */
    public static function platformFromUrl(?string $url): ?array
    {
        $host = is_string($url) ? strtolower((string) parse_url($url, PHP_URL_HOST)) : '';

        return in_array($host, self::INSTAGRAM_HOSTS, true) ? ['platform' => 'instagram', 'proof' => 'The ad link is an Instagram link'] : null;
    }

    /**
     * @return ?array<string, string> the present fields, or null when this is not a usable referral
     */
    public static function parse(mixed $referral): ?array
    {
        if (! is_array($referral)) {
            return null;
        }

        $fields = [];
        foreach (self::FIELDS as $key => $max) {
            $value = $referral[$key] ?? null;
            if (is_int($value)) {
                $value = (string) $value;
            }
            if (is_string($value) && trim($value) !== '') {
                $fields[$key] = mb_substr(trim($value), 0, $max);
            }
        }

        // Something that identifies the ad must be there; anything else is not evidence.
        return isset($fields['source_id']) || isset($fields['source_url']) || isset($fields['source_type']) ? $fields : null;
    }

    /** "Meta ad" — with the platform only when it was proven (never in Sprint 1). */
    /**
     * "Meta ad", or "Meta boosted post" for source_type post (paid ads and boosted posts are told
     * apart), with "· Instagram" / "· Facebook" only when the platform was proven.
     */
    public static function label(?string $platform = null, ?string $sourceType = null): string
    {
        $base = $sourceType === 'post' ? 'Meta boosted post' : 'Meta ad';

        return match ($platform) {
            'instagram' => $base.' · Instagram',
            'facebook' => $base.' · Facebook',
            default => $base,
        };
    }
}
