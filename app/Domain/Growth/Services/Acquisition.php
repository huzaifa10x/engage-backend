<?php

declare(strict_types=1);

namespace App\Domain\Growth\Services;

use App\Domain\Growth\GrowthSettings;
use App\Domain\Messaging\Models\Contact;
use Illuminate\Support\Carbon;

/**
 * How a contact's acquisition is shown (Blueprint v1.4 statuses). Agents get the chip only;
 * people allowed to see evidence get the details. Status keys:
 *   before        Before tracking — the contact existed before Growth Hub started for the workspace
 *   meta          Meta ad — the first message came from a click-to-WhatsApp ad
 *   unattributed  no proof of source
 * (ref, multi and web arrive with website tracking.)
 */
final class Acquisition
{
    public const LABELS = [
        'before' => 'Before tracking',
        'meta' => 'Meta ad',
        'ref' => 'Reference matched',
        'multi' => 'Multiple sources',
        'web' => 'Website · details unavailable',
        'unattributed' => 'Unattributed',
    ];

    private const SOURCE_STATUS = ['meta_ad' => 'meta'];

    public static function beforeTracking(Contact $contact, GrowthSettings $settings): bool
    {
        return $settings->startedAt !== null && $contact->created_at !== null && $contact->created_at->lt($settings->startedAt);
    }

    /**
     * @return array<string, mixed>|null null when Growth Hub is off for the workspace
     */
    public static function present(Contact $contact, GrowthSettings $settings, bool $details): ?array
    {
        if (! $settings->enabled) {
            return null;
        }

        $original = self::touch($contact->getAttribute('acquisition_original'));
        $latest = self::touch($contact->getAttribute('acquisition_latest'));
        $before = self::beforeTracking($contact, $settings);

        $status = $before ? 'before' : ($original !== null ? (self::SOURCE_STATUS[$original['source']] ?? 'unattributed') : 'unattributed');
        // The chip names the platform-aware source ("Meta ad · Instagram") when there is one.
        $chip = $status === 'meta' ? (string) $original['label'] : self::LABELS[$status];
        if ($before && $latest !== null) {
            $chip = 'Before tracking · Latest: '.$latest['label'];
        }

        $out = ['status' => $status, 'label' => $chip];
        if (! $details) {
            return $out;
        }

        $first = $contact->getAttribute('first_tracked_enquiry_at');

        return $out + [
            // "Unknown" for contacts created after tracking started with no trustworthy source.
            'original' => $before ? ['label' => 'Before tracking'] : ($original ?? ['label' => 'Unknown source']),
            'latest' => $latest,
            'first_tracked_enquiry_at' => $first !== null ? Carbon::parse($first)->toIso8601String() : null,
            'landing_page' => $original['landing_path'] ?? $latest['landing_path'] ?? null,
            'confidence' => $original !== null || $latest !== null ? 'confirmed' : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function touch(mixed $raw): ?array
    {
        return is_array($raw) && isset($raw['source'], $raw['label']) ? $raw : null;
    }
}
