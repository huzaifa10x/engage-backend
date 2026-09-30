<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Tenancy\Enums\TenantStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cross-tenant aggregates for the Super Admin. Must run inside TenantContext::bypass()
 * (guaranteed by the admin PlatformContext middleware). Money is USD minor units.
 */
final class PlatformMetrics
{
    /** @return array<string, int> */
    public function kpis(): array
    {
        $tenants = DB::table('tenants')->whereNull('deleted_at')->selectRaw(
            'count(*) as total, '.
            "count(*) filter (where status = 'active') as active, ".
            "count(*) filter (where status = 'suspended') as suspended, ".
            "count(*) filter (where created_at >= now() - interval '30 days') as new_30d"
        )->first();

        $subs = $this->liveSubscriptions()->selectRaw(
            "count(*) filter (where s.status = 'trialing') as trialing, ".
            "count(*) filter (where s.status in ('active','past_due') and coalesce(pv.price_monthly_minor, 0) > 0) as paying, ".
            "count(*) filter (where s.status = 'past_due') as past_due, ".
            "coalesce(sum(pv.price_monthly_minor) filter (where s.status in ('active','past_due')), 0) as mrr_minor"
        )->first();

        return [
            'companies' => (int) $tenants->total,
            'active' => (int) $tenants->active,
            'suspended' => (int) $tenants->suspended,
            'new_30d' => (int) $tenants->new_30d,
            'trialing' => (int) $subs->trialing,
            'paying' => (int) $subs->paying,
            'past_due' => (int) $subs->past_due,
            'mrr_minor' => (int) $subs->mrr_minor,
            'users' => (int) DB::table('users')->count(),
        ];
    }

    /** @return list<array{key: string, name: string, companies: int, trialing: int, mrr_minor: int}> */
    public function planMix(): array
    {
        $rows = $this->liveSubscriptions()
            ->groupBy('p.key', 'p.name', 'p.sort_order')
            ->orderBy('p.sort_order')
            ->selectRaw(
                'p.key, p.name, count(*) as companies, '.
                "count(*) filter (where s.status = 'trialing') as trialing, ".
                "coalesce(sum(pv.price_monthly_minor) filter (where s.status in ('active','past_due')), 0) as mrr_minor"
            )->get();

        // Companies with no live subscription resolve to the fallback (Free) plan.
        $withoutSubscription = DB::table('tenants as t')->whereNull('t.deleted_at')
            ->whereNotExists(fn ($q) => $q->from('subscriptions as s')->whereColumn('s.tenant_id', 't.id')
                ->whereIn('s.status', $this->liveStatuses()))
            ->count();

        $mix = $rows->map(fn ($r) => [
            'key' => $r->key, 'name' => $r->name, 'companies' => (int) $r->companies,
            'trialing' => (int) $r->trialing, 'mrr_minor' => (int) $r->mrr_minor,
        ])->keyBy('key');

        if ($withoutSubscription > 0) {
            $free = $mix->get('free', ['key' => 'free', 'name' => 'Free', 'companies' => 0, 'trialing' => 0, 'mrr_minor' => 0]);
            $free['companies'] += $withoutSubscription;
            $mix->put('free', $free);
        }

        return $mix->values()->all();
    }

    /** @return list<array<string, mixed>> */
    public function latestSignups(int $limit): array
    {
        return DB::table('tenants as t')->whereNull('t.deleted_at')
            ->leftJoin('subscriptions as s', fn ($j) => $j->on('s.tenant_id', '=', 't.id')->whereIn('s.status', $this->liveStatuses()))
            ->leftJoin('plan_versions as pv', 'pv.id', '=', 's.plan_version_id')
            ->leftJoin('plans as p', 'p.id', '=', 'pv.plan_id')
            ->orderByDesc('t.created_at')->limit($limit)
            ->get(['t.id', 't.name', 't.status', 't.created_at', 'p.name as plan', 's.status as subscription_status'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /** @return list<array<string, mixed>> */
    public function trialsEnding(int $days): array
    {
        return DB::table('subscriptions as s')
            ->join('tenants as t', 't.id', '=', 's.tenant_id')
            ->join('plan_versions as pv', 'pv.id', '=', 's.plan_version_id')
            ->join('plans as p', 'p.id', '=', 'pv.plan_id')
            ->where('s.status', SubscriptionStatus::Trialing->value)
            ->where('s.trial_ends_at', '<=', now()->addDays($days))
            ->orderBy('s.trial_ends_at')->limit(20)
            ->get(['t.id', 't.name', 'p.name as plan', 's.trial_ends_at'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * Tech Partner eligibility (blueprint "Partner status roadmap"). Messaging volume, active
     * clients and number quality need the Phase 2+ message pipeline — reported as not yet
     * measurable rather than guessed.
     *
     * @return list<array{criterion: string, target: string, current: ?string, status: string}>
     */
    public function partnerEligibility(): array
    {
        $activeCompanies = DB::table('tenants')->whereNull('deleted_at')->where('status', TenantStatus::Active->value)->count();

        return [
            ['criterion' => 'Tech Provider onboarding steps complete', 'target' => 'All steps', 'current' => null, 'status' => 'manual'],
            ['criterion' => 'Average daily messages, trailing 7 days', 'target' => '≥ 2,500', 'current' => null, 'status' => 'not_measurable'],
            ['criterion' => 'Active clients (≥ 1 message in 30 days)', 'target' => '≥ 10', 'current' => $activeCompanies.' active companies', 'status' => 'not_measurable'],
            ['criterion' => 'Lowest quality rating across numbers', 'target' => '≥ 90%', 'current' => null, 'status' => 'not_measurable'],
        ];
    }

    private function liveSubscriptions(): Builder
    {
        return DB::table('subscriptions as s')
            ->join('plan_versions as pv', 'pv.id', '=', 's.plan_version_id')
            ->join('plans as p', 'p.id', '=', 'pv.plan_id')
            ->whereIn('s.status', $this->liveStatuses());
    }

    /** @return list<string> */
    private function liveStatuses(): array
    {
        return array_map(fn (SubscriptionStatus $s) => $s->value, SubscriptionStatus::live());
    }
}
