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
    /** Candidate fields (v1.2 §2) and their maximum stored length. */
    public const FIELDS = [
        'source_id' => 64, 'source_type' => 20, 'source_url' => 2048, 'headline' => 512,
        'body' => 2048, 'ctwa_clid' => 512, 'media_type' => 20,
    ];

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
    public static function label(?string $platform = null): string
    {
        return match ($platform) {
            'instagram' => 'Meta ad · Instagram',
            'facebook' => 'Meta ad · Facebook',
            default => 'Meta ad',
        };
    }
}
