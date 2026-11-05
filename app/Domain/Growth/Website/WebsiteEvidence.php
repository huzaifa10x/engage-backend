<?php

declare(strict_types=1);

namespace App\Domain\Growth\Website;

use App\Domain\Growth\Models\GrowthEvidence;

/**
 * Lead details › Evidence for a website tap (prototype "Lead details"): the website, the page with
 * the WhatsApp button, the landing page remembered for the tab, the UTM campaign details, the masked
 * reference, and whether Google or Meta sent a click ID (never its value). Empty for other evidence.
 */
final class WebsiteEvidence
{
    private const LABELS = [
        'google_ads' => 'Google Ads', 'google_organic' => 'Google organic', 'meta_ad' => 'Meta ad', 'paid_other' => 'Paid campaign',
        'organic' => 'Organic', 'referral' => 'Referral', 'direct' => 'Direct', 'website' => 'Website · details unavailable', 'unattributed' => 'Unattributed',
    ];

    /** @return array<string, mixed> */
    public static function present(GrowthEvidence $e): array
    {
        if (! in_array($e->evidence_type, [GrowthEvidence::TYPE_WEBSITE, GrowthEvidence::TYPE_WEBSITE_UNREGISTERED], true)) {
            return [];
        }
        $f = $e->provider_fields;
        $label = self::LABELS[$e->source] ?? $e->source;
        if ($e->source === 'meta_ad' && ($f['platform'] ?? null) !== null) {
            $label .= ' · '.ucfirst((string) $f['platform']);
        }

        return [
            'label' => $label,
            'details' => array_filter([
                'site' => $f['site'] ?? null,
                'page_path' => $f['page_path'] ?? null,
                'landing_path' => $f['landing_path'] ?? null,
                'campaign' => ! empty($f['campaign']) ? $f['campaign'] : null,
                'referrer_host' => $f['referrer_host'] ?? null,
                'campaign_params_origin' => $f['campaign_params_origin'] ?? null,
                'reference' => $f['reference_masked'] ?? null,
                // Which click IDs came with the tap — never their values.
                'click_ids_sent' => array_keys(array_filter((array) ($f['click_ids'] ?? []))) ?: null,
                'note' => $f['note'] ?? null,
                'reused_other_sender' => $e->confidence === 'reused_other_sender' ? true : null,
            ], fn ($v) => $v !== null),
        ];
    }
}
