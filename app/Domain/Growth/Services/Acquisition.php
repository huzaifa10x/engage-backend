<?php

declare(strict_types=1);

namespace App\Domain\Growth\Services;

use App\Domain\Growth\GrowthSettings;
use App\Domain\Messaging\Models\Contact;
use Illuminate\Support\Carbon;

/**
 * How a contact's acquisition is shown (Blueprint v1.4 statuses). Agents get the chip only;
 * people allowed to see evidence get the details. Status keys:
 *   (a returning contact — created before tracking started, or first message not from a paid source —
 *    is shown with the paid touch that brought it back, flagged `returning`)
 *   meta          Meta ad — the first message came from a click-to-WhatsApp ad
 *   ref           Reference matched — a website tap (registered click) matched to the first message
 *   multi         Multiple sources — a Meta ad and a website code on the same message (Meta is the origin)
 *   web           Website · details unavailable — a valid code whose tap never registered
 *   unattributed  no proof of source
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

    /** Evidence type of the original touch → status. */
    private const TYPE_STATUS = ['meta_ctwa' => 'meta', 'website_reference' => 'ref', 'website_unregistered' => 'web'];

    public static function beforeTracking(Contact $contact, GrowthSettings $settings): bool
    {
        return $settings->startedAt !== null && $contact->created_at !== null && $contact->created_at->lt($settings->startedAt);
    }

    /**
     * $summary: the Source panel line everyone who sees the chat may read (source name, ad headline or
     * campaign, landing page — never codes, click IDs or costs). $details: the evidence-backed fields
     * (growth.view only).
     *
     * Lead tracking is for paid advertising: a contact gets a source only when a paid source brought
     * it (its first message, or a later touch). Ordinary chats and contacts show no lead source at all —
     * no "Before tracking" or "Unattributed" chip in Contacts or Chat.
     *
     * @return array<string, mixed>|null null when Growth Hub is off, or the contact is not an ad lead
     */
    public static function present(Contact $contact, GrowthSettings $settings, bool $details, bool $summary = false): ?array
    {
        if (! $settings->enabled) {
            return null;
        }

        $original = self::touch($contact->getAttribute('acquisition_original'));
        $latest = self::touch($contact->getAttribute('acquisition_latest'));
        if (! self::paid($original) && ! self::paid($latest)) {
            return null;
        }
        $before = self::beforeTracking($contact, $settings);

        // A returning contact (it existed before tracking started, or its first message was not from a
        // paid source) is shown with the paid touch that brought it back — never as "Before tracking".
        // Marketing still credits only the original source; this is how the lead is shown.
        $returning = $before || ! self::paid($original);
        $shown = $returning ? $latest : $original;
        if ($shown === null) {
            return null;
        }
        $status = self::statusOf($shown);
        // The chip names the platform-aware source ("Meta ad · Instagram", "Google Ads").
        $chip = match ($status) {
            'meta' => (string) $shown['label'],
            'ref' => 'Reference matched · '.$shown['label'],
            'multi' => 'Multiple sources · '.$shown['label'],
            default => self::LABELS[$status],
        };

        $out = ['status' => $status, 'label' => $chip, 'returning' => $returning];
        if ($summary || $details) {
            $out['source'] = self::summary($shown, $returning ? null : $latest);
        }
        if (! $details) {
            return $out;
        }

        $first = $contact->getAttribute('first_tracked_enquiry_at');

        return $out + [
            // Returning contacts: what they were before this paid touch.
            'original' => $returning
                ? ['label' => $before ? 'Existing contact (from before tracking started)' : ($original !== null ? (string) $original['label'] : 'Existing contact, no paid source')] + ($before ? [] : (array) $original)
                : $original,
            'latest' => $latest,
            'first_tracked_enquiry_at' => $first !== null ? Carbon::parse($first)->toIso8601String() : null,
            'landing_page' => $shown['landing_path'] ?? null,
            'confidence' => 'confirmed',
        ];
    }

    /**
     * Source panel (Technical Specification v1.2 §6, prototype): the source name, the campaign only
     * from permitted evidence (UTM campaign, or the Meta ad headline Meta sent), the landing path.
     * A later paid touch of a contact whose original source was paid is shown apart as `latest`
     * ("Latest: winter_sale (Google Ads)") and never replaces the original.
     *
     * @param  array<string, mixed>  $shown
     * @param  array<string, mixed>|null  $latest
     * @return array{name: string, campaign: ?string, landing_page: ?string, latest: ?array{name: string, campaign: ?string, line: string, at: ?string}}
     */
    private static function summary(array $shown, ?array $latest): array
    {
        $later = $latest !== null && self::paid($latest) && ($latest['evidence_id'] ?? null) !== ($shown['evidence_id'] ?? null) ? self::touchLine($latest) : null;

        return ['name' => (string) $shown['label'], 'campaign' => self::campaignOf($shown), 'landing_page' => $shown['landing_path'] ?? null, 'latest' => $later];
    }

    /** @param array<string, mixed> $touch */
    private static function campaignOf(array $touch): ?string
    {
        $campaign = $touch['campaign'] ?? null;
        if (($campaign === null || $campaign === '') && ! empty($touch['headline'])) {
            $campaign = 'Ad: "'.$touch['headline'].'"';
        }

        return $campaign ?: null;
    }

    /**
     * "Latest: winter_sale (Google Ads)", or "Latest: Google Ads" when the touch had no campaign.
     *
     * @param  array<string, mixed>  $touch
     * @return array{name: string, campaign: ?string, line: string, at: ?string}
     */
    private static function touchLine(array $touch): array
    {
        $campaign = self::campaignOf($touch);
        $name = (string) $touch['label'];

        return ['name' => $name, 'campaign' => $campaign, 'line' => 'Latest: '.($campaign !== null ? "{$campaign} ({$name})" : $name), 'at' => $touch['occurred_at'] ?? null];
    }

    /**
     * Sources that count as paid advertising: Meta ads (Click-to-WhatsApp, or a website tap from a
     * Meta ad), Google Ads (gclid / gbraid / wbraid or a paid Google UTM) and other paid UTM campaigns.
     * Organic, direct and referral website taps are recorded and counted in Analytics › Marketing,
     * but they are not leads.
     */
    public const PAID_SOURCES = ['meta_ad', 'google_ads', 'paid_other'];

    /** @param array<string, mixed> $touch */
    public static function statusOf(array $touch): string
    {
        $status = self::TYPE_STATUS[$touch['type'] ?? ''] ?? (($touch['source'] ?? null) === 'meta_ad' ? 'meta' : 'unattributed');

        return ! empty($touch['conflict']) && $status === 'meta' ? 'multi' : $status;
    }

    /** @param array<string, mixed>|null $touch */
    public static function paid(?array $touch): bool
    {
        return $touch !== null && in_array($touch['source'] ?? null, self::PAID_SOURCES, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function touch(mixed $raw): ?array
    {
        if (! is_array($raw) || ! isset($raw['source'], $raw['label'])) {
            return null;
        }
        // ad_id groups leads by ad on the server; Google / Meta click IDs are kept for later conversion
        // uploads. Neither is ever sent to the browser.
        unset($raw['ad_id'], $raw['click_ids']);

        return $raw;
    }
}
