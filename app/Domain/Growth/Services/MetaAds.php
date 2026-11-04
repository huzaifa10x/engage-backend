<?php

declare(strict_types=1);

namespace App\Domain\Growth\Services;

use App\Domain\Audit\AuditLogger;
use App\Domain\Growth\Models\GrowthEvidence;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The workspace's Click-to-WhatsApp ads (one per Meta ad ID). Meta's referral has no platform
 * field and an ad on both platforms keeps one ad ID, so "Meta ad · Instagram / Facebook" needs
 * proof. One proof is the customer telling us, in Tracking Setup, that an ad runs on one platform
 * only; every lead from that ad (past and future) then carries that platform with the proof.
 * A link that is itself an Instagram link wins over a tag (it is direct evidence).
 */
final class MetaAds
{
    public const TAG_PROOF = [
        'instagram' => 'This ad runs on Instagram only (set in Tracking Setup)',
        'facebook' => 'This ad runs on Facebook only (set in Tracking Setup)',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Note an ad seen on a referral. Returns the platform tagged for it, if any.
     *
     * @param  array<string, string>  $fields
     */
    public function seen(Tenant $tenant, array $fields, Carbon $at): ?string
    {
        if (! isset($fields['source_id'])) {
            return null;
        }

        DB::statement(
            'INSERT INTO growth_meta_ads (id, tenant_id, source_id, source_type, headline, source_url, first_seen_at, last_seen_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (tenant_id, source_id) DO UPDATE SET
               source_type = COALESCE(EXCLUDED.source_type, growth_meta_ads.source_type),
               headline = COALESCE(EXCLUDED.headline, growth_meta_ads.headline),
               source_url = COALESCE(EXCLUDED.source_url, growth_meta_ads.source_url),
               first_seen_at = LEAST(growth_meta_ads.first_seen_at, EXCLUDED.first_seen_at),
               last_seen_at = GREATEST(growth_meta_ads.last_seen_at, EXCLUDED.last_seen_at)',
            [(string) Str::uuid7(), $tenant->id, $fields['source_id'], $fields['source_type'] ?? null, $fields['headline'] ?? null, $fields['source_url'] ?? null, $at, $at],
        );

        $platform = DB::table('growth_meta_ads')->where('tenant_id', $tenant->id)->where('source_id', $fields['source_id'])->value('platform');

        return is_string($platform) ? $platform : null;
    }

    /**
     * Tag an ad as running on one platform only (or clear the tag) and re-label its leads.
     *
     * @return int how many leads' evidence changed
     */
    public function tag(Tenant $tenant, string $adId, ?string $platform, ?string $membershipId): int
    {
        return DB::transaction(function () use ($tenant, $adId, $platform, $membershipId) {
            $ad = DB::table('growth_meta_ads')->where('tenant_id', $tenant->id)->where('id', $adId)->lockForUpdate()->first();
            abort_if($ad === null, 404);
            DB::table('growth_meta_ads')->where('id', $adId)->update([
                'platform' => $platform, 'platform_set_by_membership_id' => $membershipId, 'platform_set_at' => now(),
            ]);

            $changed = 0;
            $rows = GrowthEvidence::query()->where('evidence_type', GrowthEvidence::TYPE_META_CTWA)
                ->whereRaw("provider_fields->'referral'->>'source_id' = ?", [$ad->source_id])->get();
            foreach ($rows as $e) {
                $fields = $e->provider_fields;
                $byUrl = MetaReferral::platformFromUrl($fields['referral']['source_url'] ?? null);
                $next = $byUrl ?? ($platform !== null ? ['platform' => $platform, 'proof' => self::TAG_PROOF[$platform]] : null);
                if (($fields['platform'] ?? null) === ($next['platform'] ?? null) && ($fields['platform_proof'] ?? null) === ($next['proof'] ?? null)) {
                    continue;
                }
                $fields['platform'] = $next['platform'] ?? null;
                $fields['platform_proof'] = $next['proof'] ?? null;
                $e->forceFill(['provider_fields' => $fields])->save();
                $this->relabel($e->crm_contact_id, $e->id, $fields['platform'], $fields['referral']['source_type'] ?? null);
                $changed++;
            }

            $this->audit->record('growth.meta_ad.platform_set', null, after: ['ad' => '••••'.substr((string) $ad->source_id, -4), 'platform' => $platform, 'leads' => $changed]);

            return $changed;
        });
    }

    /** The contact's original / latest touch that came from this evidence shows the new label. */
    private function relabel(string $contactId, string $evidenceId, ?string $platform, ?string $sourceType): void
    {
        /** @var ?Contact $contact */
        $contact = Contact::query()->withTrashed()->lockForUpdate()->find($contactId);
        if ($contact === null) {
            return;
        }
        $updates = [];
        foreach (['acquisition_original', 'acquisition_latest'] as $column) {
            $touch = $contact->getAttribute($column);
            if (is_array($touch) && ($touch['evidence_id'] ?? null) === $evidenceId) {
                $updates[$column] = ['platform' => $platform, 'label' => MetaReferral::label($platform, $sourceType), 'source_type' => $sourceType] + $touch;
            }
        }
        if ($updates !== []) {
            $contact->forceFill($updates)->save();
        }
    }
}
