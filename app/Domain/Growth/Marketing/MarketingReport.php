<?php

declare(strict_types=1);

namespace App\Domain\Growth\Marketing;

use App\Domain\Growth\GrowthSettings;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Analytics → Marketing reports (Marketing Analytics Specification §5, §7), read from
 * growth_marketing_daily. "0" is a measured zero; null ("—") means it can't be calculated (no
 * denominator, or Qualified not mapped yet). No spend, cost or revenue anywhere.
 */
final class MarketingReport
{
    /** @param array{from: string, to: string, number_id?: ?string, source?: ?string, campaign?: ?string} $f */
    public function __construct(private readonly Tenant $tenant, private readonly array $f) {}

    public function from(): string
    {
        return $this->f['from'];
    }

    public function to(): string
    {
        return $this->f['to'];
    }

    /**
     * The same number of days immediately before.
     *
     * @return array{0: string, 1: string}
     */
    public function previous(): array
    {
        $from = Carbon::parse($this->f['from']);
        $days = $from->diffInDays(Carbon::parse($this->f['to'])) + 1;

        return [$from->copy()->subDays((int) $days)->toDateString(), $from->copy()->subDay()->toDateString()];
    }

    public function mappingReady(): bool
    {
        return MarketingCohort::mapping($this->tenant)['qualified'] !== [];
    }

    public function contactedReady(): bool
    {
        return $this->mappingReady() || MarketingCohort::mapping($this->tenant)['contacted'] !== [];
    }

    /** Part of the period is younger than 30 days: some leads can still qualify. */
    public function provisional(): bool
    {
        $today = now($this->tenant->timezone ?: 'UTC')->startOfDay();

        return Carbon::parse($this->f['to'], $this->tenant->timezone ?: 'UTC')->gt($today->copy()->subDays(30));
    }

    public function updatedAt(): ?string
    {
        $at = DB::table('growth_marketing_state')->where('tenant_id', $this->tenant->id)->value('computed_at');

        return $at !== null ? Carbon::parse((string) $at)->toIso8601String() : null;
    }

    /** @return array<string, mixed> */
    public function overview(bool $compare): array
    {
        [$pf, $pt] = $this->previous();
        $cur = $this->totals($this->f['from'], $this->f['to']);
        $prev = $compare ? $this->totals($pf, $pt) : null;
        $ready = $this->mappingReady();

        $metric = fn (?float $v, ?float $p) => ['value' => $v, 'previous' => $p, 'change_pct' => self::change($v, $p)];
        $rate = fn (array $t) => $ready ? self::pct($t['qualified'], $t['new']) : null;
        $cov = fn (array $t) => self::pct($t['confirmed'], $t['new']);

        return [
            'period' => ['from' => $this->f['from'], 'to' => $this->f['to'], 'timezone' => $this->tenant->timezone ?: 'UTC'],
            'previous_period' => $compare ? ['from' => $pf, 'to' => $pt] : null,
            'provisional' => $ready && $this->provisional(),
            'stage_mapping_ready' => $ready,
            'new_leads' => $metric($cur['new'], $prev['new'] ?? null),
            'qualified_leads' => $ready ? $metric($cur['qualified'], $prev['qualified'] ?? null) : ['value' => null, 'previous' => null, 'change_pct' => null],
            'qualification_rate' => ['value' => $rate($cur), 'previous' => $prev !== null ? $rate($prev) : null],
            'confirmed_coverage' => ['value' => $cov($cur), 'previous' => $prev !== null ? $cov($prev) : null, 'confirmed' => $cur['confirmed']],
            'returning_contacts' => $this->returning(),
            'updated_at' => $this->updatedAt(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function sources(): array
    {
        [$pf, $pt] = $this->previous();
        $cur = $this->grouped('source_bucket', $this->f['from'], $this->f['to']);
        $prev = $this->grouped('source_bucket', $pf, $pt);
        $ready = $this->mappingReady();

        $rows = [];
        foreach (MarketingCohort::BUCKETS as $bucket => $label) {
            $c = $cur[$bucket] ?? ['new' => 0, 'qualified' => 0];
            $p = $prev[$bucket] ?? ['new' => 0, 'qualified' => 0];
            if ($c['new'] === 0 && $p['new'] === 0) {
                continue;
            }
            $rows[] = [
                'bucket' => $bucket, 'label' => $label,
                'new_leads' => $c['new'],
                'qualified' => $ready ? $c['qualified'] : null,
                'rate' => $ready ? self::pct($c['qualified'], $c['new']) : null,
                'previous' => ['new_leads' => $p['new'], 'qualified' => $ready ? $p['qualified'] : null],
                'change_pct' => self::change((float) $c['new'], (float) $p['new']),
            ];
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function campaigns(int $limit = 50): array
    {
        [$pf, $pt] = $this->previous();
        $cur = $this->grouped('campaign_key', $this->f['from'], $this->f['to'], withBuckets: true);
        $prev = $this->grouped('campaign_key', $pf, $pt);
        $ready = $this->mappingReady();
        $labels = $this->campaignLabels(array_keys($cur));

        $rows = [];
        foreach ($cur as $key => $c) {
            $buckets = array_values(array_unique($c['buckets'] ?? []));
            $p = $prev[$key]['new'] ?? 0;
            $rows[] = [
                'campaign_key' => $key,
                'label' => $labels[$key] ?? 'Campaign not available',
                'bucket' => count($buckets) === 1 ? $buckets[0] : null,
                'source' => count($buckets) === 1 ? MarketingCohort::BUCKETS[$buckets[0]] ?? $buckets[0] : 'Several',
                'new_leads' => $c['new'],
                'qualified' => $ready ? $c['qualified'] : null,
                'rate' => $ready ? self::pct($c['qualified'], $c['new']) : null,
                'previous_new_leads' => $p,
                'change_pct' => self::change((float) $c['new'], (float) $p),
                'is_new' => $p === 0 && $c['new'] > 0 && $key !== 'none',
            ];
        }
        usort($rows, fn ($a, $b) => [$a['campaign_key'] === 'none', -$a['new_leads']] <=> [$b['campaign_key'] === 'none', -$b['new_leads']]);

        return array_slice($rows, 0, $limit);
    }

    /** @return list<array{step: string, count: ?int}> */
    public function funnel(): array
    {
        $t = $this->totals($this->f['from'], $this->f['to']);

        return [
            ['step' => 'new', 'count' => $t['new']],
            ['step' => 'contacted', 'count' => $this->contactedReady() ? $t['contacted'] : null],
            ['step' => 'qualified', 'count' => $this->mappingReady() ? $t['qualified'] : null],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function trend(bool $compare): array
    {
        [$pf] = $this->previous();
        $cur = $this->daily($this->f['from'], $this->f['to']);
        $prev = $compare ? $this->daily($pf, Carbon::parse($this->f['from'])->subDay()->toDateString()) : [];
        $ready = $this->mappingReady();

        $points = [];
        $day = Carbon::parse($this->f['from']);
        $pday = Carbon::parse($pf);
        $end = Carbon::parse($this->f['to']);
        while ($day->lte($end)) {
            $d = $day->toDateString();
            $p = $pday->toDateString();
            $points[] = [
                'date' => $d,
                'new_leads' => $cur[$d]['new'] ?? 0,
                'qualified' => $ready ? ($cur[$d]['qualified'] ?? 0) : null,
                'prev_date' => $compare ? $p : null,
                'prev_new_leads' => $compare ? ($prev[$p]['new'] ?? 0) : null,
            ];
            $day->addDay();
            $pday->addDay();
        }

        return $points;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────────────────

    private function base(string $from, string $to): Builder
    {
        $q = DB::table('growth_marketing_daily')->where('tenant_id', $this->tenant->id)->whereBetween('local_date', [$from, $to]);
        if (! empty($this->f['number_id'])) {
            $q->where('number_id', $this->f['number_id']);
        }
        if (! empty($this->f['source'])) {
            $this->f['source'] === 'meta'
                ? $q->whereIn('source_bucket', ['meta_instagram', 'meta_facebook', 'meta_unsplit'])
                : $q->where('source_bucket', $this->f['source']);
        }
        if (! empty($this->f['campaign'])) {
            $q->where('campaign_key', $this->f['campaign']);
        }

        return $q;
    }

    /** @return array{new: int, contacted: int, qualified: int, confirmed: int} */
    private function totals(string $from, string $to): array
    {
        $confirmed = "'".implode("','", MarketingCohort::CONFIRMED)."'";
        $r = $this->base($from, $to)->selectRaw(
            "coalesce(sum(new_leads), 0) AS n, coalesce(sum(contacted_30d), 0) AS c, coalesce(sum(qualified_30d), 0) AS q,
             coalesce(sum(new_leads) FILTER (WHERE source_bucket IN ({$confirmed})), 0) AS conf"
        )->first();

        return ['new' => (int) ($r->n ?? 0), 'contacted' => (int) ($r->c ?? 0), 'qualified' => (int) ($r->q ?? 0), 'confirmed' => (int) ($r->conf ?? 0)];
    }

    /** @return array<string, array{new: int, qualified: int, buckets?: list<string>}> */
    private function grouped(string $column, string $from, string $to, bool $withBuckets = false): array
    {
        $rows = $this->base($from, $to)
            ->selectRaw("{$column} AS k, sum(new_leads) AS n, sum(qualified_30d) AS q".($withBuckets ? ', array_agg(DISTINCT source_bucket) AS b' : ''))
            ->groupBy($column)->get();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->k] = ['new' => (int) $r->n, 'qualified' => (int) $r->q]
                + ($withBuckets ? ['buckets' => array_values(array_filter(explode(',', trim((string) $r->b, '{}'))))] : []);
        }

        return $out;
    }

    /** @return array<string, array{new: int, qualified: int}> */
    private function daily(string $from, string $to): array
    {
        $rows = $this->base($from, $to)->selectRaw('local_date AS d, sum(new_leads) AS n, sum(qualified_30d) AS q')->groupBy('local_date')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[Carbon::parse((string) $r->d)->toDateString()] = ['new' => (int) $r->n, 'qualified' => (int) $r->q];
        }

        return $out;
    }

    /**
     * "Glow in 30 minutes" (Meta ad) for an ad, the utm_campaign for a website campaign.
     *
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function campaignLabels(array $keys): array
    {
        $ids = array_values(array_filter(array_map(fn (string $k) => str_starts_with($k, 'meta:') ? substr($k, 5) : null, $keys)));
        $ads = $ids === [] ? collect() : DB::table('growth_meta_ads')->where('tenant_id', $this->tenant->id)->whereIn('id', $ids)->get(['id', 'headline', 'source_type'])->keyBy('id');

        $out = [];
        foreach ($keys as $k) {
            if (str_starts_with($k, 'meta:')) {
                $ad = $ads[substr($k, 5)] ?? null;
                $kind = ($ad->source_type ?? null) === 'post' ? 'Meta boosted post' : 'Meta ad';
                $out[$k] = $ad !== null && $ad->headline ? "\u{201C}{$ad->headline}\u{201D} ({$kind})" : "Untitled ad ({$kind})";
            } elseif (str_starts_with($k, 'utm:')) {
                $out[$k] = substr($k, 4);
            }
        }

        return $out;
    }

    /** Contacts from before tracking with a new tracked touch in the period (never counted as new leads). */
    private function returning(): int
    {
        $started = GrowthSettings::for($this->tenant)->startedAt;
        if ($started === null) {
            return 0;
        }
        $tz = $this->tenant->timezone ?: 'UTC';

        return (int) DB::table('growth_evidence as e')->join('contacts as c', 'c.id', '=', 'e.crm_contact_id')
            ->where('e.tenant_id', $this->tenant->id)->where('c.created_at', '<', $started)
            ->whereRaw('(e.occurred_at AT TIME ZONE ?)::date BETWEEN ?::date AND ?::date', [$tz, $this->f['from'], $this->f['to']])
            ->distinct()->count('e.crm_contact_id');
    }

    private static function pct(int|float $part, int|float $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }

    private static function change(?float $value, ?float $previous): ?float
    {
        return $value !== null && $previous !== null && $previous > 0 ? round(($value - $previous) / $previous * 100, 1) : null;
    }
}
