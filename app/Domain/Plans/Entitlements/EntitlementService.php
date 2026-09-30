<?php

declare(strict_types=1);

namespace App\Domain\Plans\Entitlements;

use App\Domain\Billing\Models\Subscription;
use App\Domain\Plans\Exceptions\FeatureNotAvailable;
use App\Domain\Plans\Exceptions\PlanLimitReached;
use App\Domain\Plans\FeatureKey;
use App\Domain\Plans\Models\Plan;
use App\Domain\Plans\Models\PlanVersion;
use App\Domain\Plans\Models\PlanVersionFeature;
use App\Domain\Plans\Models\TenantEntitlementOverride;
use App\Domain\Plans\Usage\UsageCounterRegistry;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Cache as CacheFacade;
use LogicException;

/**
 * Plans → Plan Versions → Features → (Subscription) → Overrides → Entitlements.
 *
 * The ONLY place that answers "may this tenant do X / how many". Enforced server-side before
 * growth actions (adding seats, numbers, contacts, launching broadcasts). NEVER gate inbound
 * messages or inbox replies — those must work even over the limit (blueprint "non-gating rule").
 */
final class EntitlementService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Cache $cache,
        private readonly UsageCounterRegistry $counters,
    ) {}

    public function for(Tenant $tenant): EntitlementSet
    {
        /** @var array<string, mixed> $data */
        $data = $this->cache->remember(
            $this->cacheKey($tenant),
            (int) config('engage.entitlements.cache_ttl', 300),
            fn () => $this->context->bypass(fn () => $this->resolve($tenant))->toArray(),
        );

        return EntitlementSet::fromArray($data);
    }

    public function allows(Tenant $tenant, FeatureKey $feature): bool
    {
        return $this->for($tenant)->allows($feature);
    }

    /** @throws FeatureNotAvailable */
    public function ensureEnabled(Tenant $tenant, FeatureKey $feature): void
    {
        if (! $this->allows($tenant, $feature)) {
            throw new FeatureNotAvailable($feature, $this->for($tenant)->planKey);
        }
    }

    public function usage(Tenant $tenant, FeatureKey $feature): ?int
    {
        return $this->counters->for($feature)?->current($tenant);
    }

    /**
     * @throws FeatureNotAvailable
     * @throws PlanLimitReached
     */
    public function ensureWithinLimit(Tenant $tenant, FeatureKey $feature, int $adding = 1): void
    {
        $entitlement = $this->for($tenant)->get($feature);

        if (! $entitlement->enabled) {
            throw new FeatureNotAvailable($feature, $this->for($tenant)->planKey);
        }

        if ($entitlement->limit === null) {
            return;
        }

        $counter = $this->counters->for($feature)
            ?? throw new LogicException("No usage counter registered for limit feature [{$feature->value}].");

        $current = $counter->current($tenant);

        if (! $entitlement->permits($current + $adding)) {
            throw new PlanLimitReached($feature, $entitlement->limit, $current);
        }
    }

    /**
     * Check-then-act under a per-tenant/feature lock so concurrent requests cannot both pass the
     * check and exceed the limit.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withinLimit(Tenant $tenant, FeatureKey $feature, int $adding, callable $callback): mixed
    {
        $lock = CacheFacade::lock("entitlement-lock:{$tenant->getKey()}:{$feature->value}", (int) config('engage.entitlements.lock_seconds', 10));

        return $lock->block(5, function () use ($tenant, $feature, $adding, $callback) {
            $this->ensureWithinLimit($tenant, $feature, $adding);

            return $callback();
        });
    }

    public function forget(Tenant $tenant): void
    {
        $this->cache->forget($this->cacheKey($tenant));
    }

    private function resolve(Tenant $tenant): EntitlementSet
    {
        $subscription = Subscription::query()
            ->where('tenant_id', $tenant->getKey())
            ->live()
            ->with('planVersion.plan')
            ->latest('created_at')
            ->first();

        $version = $subscription?->planVersion ?? $this->fallbackVersion();

        $planFeatures = PlanVersionFeature::query()
            ->where('plan_version_id', $version->getKey())
            ->with('feature')
            ->get()
            ->filter(fn (PlanVersionFeature $row) => $row->feature !== null)
            ->keyBy(fn (PlanVersionFeature $row) => $row->feature->key);

        $overrides = TenantEntitlementOverride::query()
            ->where('tenant_id', $tenant->getKey())
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with('feature')
            ->get()
            ->filter(fn (TenantEntitlementOverride $o) => $o->feature !== null)
            ->keyBy(fn (TenantEntitlementOverride $o) => $o->feature->key);

        $entitlements = [];

        foreach (FeatureKey::cases() as $key) {
            /** @var PlanVersionFeature|null $row */
            $row = $planFeatures->get($key->value);
            /** @var TenantEntitlementOverride|null $override */
            $override = $overrides->get($key->value);

            $enabled = $row !== null && $row->enabled;
            $limit = $row?->limit_value;
            $config = $row?->config ?? [];

            if ($override !== null) {
                $enabled = $override->enabled ?? $enabled;
                $limit = $override->getAttribute('unlimited') ? null : ($override->limit_value ?? $limit);
                $config = array_replace($config, $override->config ?? []);
            }

            $entitlements[$key->value] = new Entitlement($key->value, $key->type(), $enabled, $limit, $config);
        }

        return new EntitlementSet(
            planKey: $version->plan->key,
            planName: $version->plan->name,
            planVersionId: (string) $version->getKey(),
            planVersion: $version->version,
            subscriptionStatus: $subscription?->status->value,
            trialEndsAt: $subscription?->trial_ends_at?->toIso8601String(),
            entitlements: $entitlements,
        );
    }

    private function fallbackVersion(): PlanVersion
    {
        $version = Plan::byKey((string) config('engage.plans.fallback', 'free'))->activeVersionOrFail();
        $version->loadMissing('plan');

        return $version;
    }

    private function cacheKey(Tenant $tenant): string
    {
        return "entitlements:v1:{$tenant->getKey()}";
    }
}
