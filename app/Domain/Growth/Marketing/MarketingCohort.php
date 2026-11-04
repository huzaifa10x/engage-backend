<?php

declare(strict_types=1);

namespace App\Domain\Growth\Marketing;

use App\Domain\Growth\GrowthSettings;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * The new-lead cohort behind Analytics → Marketing (Marketing Analytics Specification §2–§5), as one
 * SQL query shared by the daily table and the Leads drill-down, so a clicked number and the list
 * it opens are computed the same way.
 *
 * One row per lead (canonical CRM contact):
 *  - only contacts created after Growth Hub started (never "Before tracking");
 *  - first_at: their first inbound WhatsApp message; local_date: that day in the workspace's time zone;
 *  - number_id: the number that first message came in on;
 *  - bucket: exactly one source bucket from the original source (§4);
 *  - campaign_key: "meta:<growth_meta_ads.id>" for a Click-to-WhatsApp ad, "utm:<campaign>" for a
 *    website lead, "none" otherwise ("Campaign not available");
 *  - contacted / qualified: reached a mapped stage within 30 days of first_at (stage history).
 *    Qualified stages also count as Contacted ("Contacted or later").
 */
final class MarketingCohort
{
    public const BUCKETS = [
        'meta_instagram' => 'Meta ad · Instagram',
        'meta_facebook' => 'Meta ad · Facebook',
        'meta_unsplit' => 'Meta ad (platform not split)',
        'google_ads' => 'Google Ads',
        'google_organic' => 'Google organic',
        'organic' => 'Organic',
        'direct' => 'Direct',
        'other_campaign' => 'Other campaign',
        'website_unavailable' => 'Website · details unavailable',
        'unattributed' => 'Unattributed',
    ];

    /** Buckets with confirmed evidence (reference matched, Meta ad, multiple sources). */
    public const CONFIRMED = ['meta_instagram', 'meta_facebook', 'meta_unsplit', 'google_ads', 'google_organic', 'organic', 'direct', 'other_campaign'];

    /**
     * @param  array{contacted: list<string>, qualified: list<string>}  $mapping
     * @param  ?string  $from  local date (Y-m-d), inclusive
     * @param  ?string  $to  local date (Y-m-d), inclusive
     * @return array{0: string, 1: list<mixed>} SQL and bindings
     */
    public static function sql(Tenant $tenant, array $mapping, ?string $from = null, ?string $to = null): array
    {
        $started = GrowthSettings::for($tenant)->startedAt;
        $tz = $tenant->timezone ?: 'UTC';
        $qualified = $mapping['qualified'];
        $contacted = array_values(array_unique([...$mapping['contacted'], ...$qualified]));

        $sql = <<<'SQL'
            SELECT l.contact_id, l.first_at, l.local_date, l.number_id, l.bucket, l.campaign_key,
                   EXISTS (SELECT 1 FROM contact_stage_events e WHERE e.tenant_id = l.tenant_id AND e.contact_id = l.contact_id
                           AND e.to_stage_id = ANY(?::uuid[]) AND e.changed_at < l.first_at + interval '30 days') AS contacted,
                   EXISTS (SELECT 1 FROM contact_stage_events e WHERE e.tenant_id = l.tenant_id AND e.contact_id = l.contact_id
                           AND e.to_stage_id = ANY(?::uuid[]) AND e.changed_at < l.first_at + interval '30 days') AS qualified
            FROM (
                SELECT c.tenant_id, c.id AS contact_id, f.at AS first_at, (f.at AT TIME ZONE ?)::date AS local_date,
                       coalesce(f.phone_number_id::text, '') AS number_id,
                       CASE
                           WHEN c.acquisition_original IS NULL THEN 'unattributed'
                           WHEN c.acquisition_original->>'source' = 'meta_ad' THEN
                               CASE c.acquisition_original->>'platform' WHEN 'instagram' THEN 'meta_instagram' WHEN 'facebook' THEN 'meta_facebook' ELSE 'meta_unsplit' END
                           WHEN c.acquisition_original->>'type' = 'website_unregistered' THEN 'website_unavailable'
                           WHEN c.acquisition_original->>'source' IN ('google_ads', 'google_organic', 'organic', 'direct') THEN c.acquisition_original->>'source'
                           WHEN c.acquisition_original->>'source' IS NOT NULL THEN 'other_campaign'
                           ELSE 'unattributed'
                       END AS bucket,
                       CASE
                           WHEN c.acquisition_original->>'source' = 'meta_ad' AND ma.id IS NOT NULL THEN 'meta:' || ma.id::text
                           WHEN c.acquisition_original->>'source' <> 'meta_ad' AND coalesce(c.acquisition_original->>'campaign', '') <> ''
                               THEN 'utm:' || left(c.acquisition_original->>'campaign', 100)
                           ELSE 'none'
                       END AS campaign_key
                FROM contacts c
                JOIN LATERAL (
                    SELECT coalesce(m.meta_timestamp, m.created_at) AS at, m.phone_number_id
                    FROM messages m
                    WHERE m.tenant_id = c.tenant_id AND m.contact_id = c.id AND m.direction = 'inbound'
                    ORDER BY coalesce(m.meta_timestamp, m.created_at), m.id
                    LIMIT 1
                ) f ON true
                LEFT JOIN growth_meta_ads ma ON ma.tenant_id = c.tenant_id AND ma.source_id = c.acquisition_original->>'ad_id'
                WHERE c.tenant_id = ? AND c.deleted_at IS NULL
                  AND (?::timestamptz IS NULL OR c.created_at >= ?::timestamptz)
            ) l
            WHERE (?::date IS NULL OR l.local_date >= ?::date) AND (?::date IS NULL OR l.local_date <= ?::date)
        SQL;

        $startedAt = $started?->toIso8601String();

        return [$sql, [
            self::pgArray($contacted), self::pgArray($qualified), $tz,
            $tenant->id, $startedAt, $startedAt,
            $from, $from, $to, $to,
        ]];
    }

    /**
     * The workspace's stage mapping.
     *
     * @return array{contacted: list<string>, qualified: list<string>}
     */
    public static function mapping(Tenant $tenant): array
    {
        $rows = DB::table('growth_stage_mapping')->where('tenant_id', $tenant->id)->get(['reporting_step', 'crm_stage_id']);
        $out = ['contacted' => [], 'qualified' => []];
        foreach ($rows as $r) {
            $out[$r->reporting_step === 'qualified' ? 'qualified' : 'contacted'][] = (string) $r->crm_stage_id;
        }

        return $out;
    }

    /** @param list<string> $ids */
    private static function pgArray(array $ids): string
    {
        return '{'.implode(',', array_map(fn (string $id) => preg_replace('/[^0-9a-f-]/i', '', $id), $ids)).'}';
    }
}
