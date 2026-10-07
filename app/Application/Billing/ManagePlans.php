<?php

declare(strict_types=1);

namespace App\Application\Billing;

use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\Enums\PlanVersionStatus;
use App\Domain\Plans\FeatureKey;
use App\Domain\Plans\Models\Feature;
use App\Domain\Plans\Models\Plan;
use App\Domain\Plans\Models\PlanVersion;
use App\Domain\Plans\Models\PlanVersionFeature;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Api\V1\PublicSiteController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin plan management. A published plan version is never edited: saving a plan
 * publishes a NEW version (pricing, limits, rate limits, features) and retires the previous one,
 * so there is always a record of exactly what each customer bought.
 */
final class ManagePlans
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, description?: ?string, is_public: bool, is_active: bool, sort_order?: ?int, price_monthly_minor: ?int, price_yearly_minor: ?int, trial_days?: ?int, features: array<string, array{enabled?: bool, limit?: ?int, unlimited?: bool, config?: ?array<string, mixed>}>}  $data
     */
    public function create(string $key, array $data): Plan
    {
        Cache::forget(PublicSiteController::CACHE_KEY); // the public pricing page reads this catalog

        return DB::transaction(function () use ($key, $data): Plan {
            $plan = Plan::query()->create([
                'key' => $key,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_public' => $data['is_public'],
                'is_active' => $data['is_active'],
                'sort_order' => $data['sort_order'] ?? ((int) Plan::query()->max('sort_order') + 1),
            ]);
            $this->publishVersion($plan, $data, null);
            $this->audit->record('plan.created', null, meta: ['plan' => $key]);

            return $plan;
        });
    }

    /**
     * Save plan details and publish a new version.
     *
     * @param  array{name: string, description?: ?string, is_public: bool, is_active: bool, sort_order?: ?int, price_monthly_minor: ?int, price_yearly_minor: ?int, trial_days?: ?int, features: array<string, array{enabled?: bool, limit?: ?int, unlimited?: bool, config?: ?array<string, mixed>}>}  $data
     * @param  bool  $applyToSubscribers  move existing subscribers to the new limits and features (their price never changes by itself)
     * @return array{version: int, moved: int}
     */
    public function update(Plan $plan, array $data, bool $applyToSubscribers): array
    {
        Cache::forget(PublicSiteController::CACHE_KEY); // the public pricing page reads this catalog

        return DB::transaction(function () use ($plan, $data, $applyToSubscribers): array {
            $plan->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_public' => $data['is_public'],
                'is_active' => $data['is_active'],
                'sort_order' => $data['sort_order'] ?? $plan->sort_order,
            ])->save();

            $previous = $plan->activeVersion();
            $version = $this->publishVersion($plan, $data, $previous);

            $moved = 0;
            if ($applyToSubscribers) {
                $old = $plan->versions()->whereKeyNot($version->id)->pluck('id');
                $tenantIds = $this->context->bypass(function () use ($old, $version): array {
                    $ids = Subscription::query()->live()->whereIn('plan_version_id', $old)->pluck('tenant_id')->all();
                    Subscription::query()->live()->whereIn('plan_version_id', $old)->update(['plan_version_id' => $version->id]);

                    return $ids;
                });
                foreach ($this->context->bypass(fn () => Tenant::query()->whereIn('id', $tenantIds)->get()) as $tenant) {
                    $this->entitlements->forget($tenant);
                }
                $moved = count($tenantIds);
            }

            $this->audit->record('plan.version_published', null, meta: ['plan' => $plan->key, 'version' => $version->version, 'subscribers_moved' => $moved]);

            return ['version' => $version->version, 'moved' => $moved];
        });
    }

    public function setActive(Plan $plan, bool $active): void
    {
        Cache::forget(PublicSiteController::CACHE_KEY); // the public pricing page reads this catalog
        $plan->forceFill(['is_active' => $active])->save();
        $this->audit->record($active ? 'plan.activated' : 'plan.deactivated', null, meta: ['plan' => $plan->key]);
    }

    /** @param array<string, mixed> $data */
    private function publishVersion(Plan $plan, array $data, ?PlanVersion $previous): PlanVersion
    {
        $monthly = $data['price_monthly_minor'] ?? null;
        $yearly = $data['price_yearly_minor'] ?? null;

        // Only one version of a plan can be active: retire the current one first.
        $plan->versions()->where('status', PlanVersionStatus::Active->value)->update(['status' => PlanVersionStatus::Retired->value]);

        $version = PlanVersion::query()->create([
            'plan_id' => $plan->id,
            'version' => ((int) $plan->versions()->max('version')) + 1,
            'status' => PlanVersionStatus::Active,
            'price_monthly_minor' => $monthly,
            'price_yearly_minor' => $yearly,
            'currency' => 'USD',
            'trial_days' => (int) ($data['trial_days'] ?? ($previous->trial_days ?? 0)),
            'published_at' => now(),
            // An unchanged price keeps its Stripe price; a changed one gets a new Stripe price on first sale.
            'stripe_price_monthly_id' => $previous !== null && $previous->price_monthly_minor === $monthly ? $previous->stripe_price_monthly_id : null,
            'stripe_price_yearly_id' => $previous !== null && $previous->price_yearly_minor === $yearly ? $previous->stripe_price_yearly_id : null,
        ]);

        $features = Feature::query()->get()->keyBy('key');
        foreach (FeatureKey::cases() as $key) {
            $input = (array) ($data['features'][$key->value] ?? []);
            $quantified = $key->type()->isQuantified();
            $enabled = (bool) ($input['enabled'] ?? false);
            $unlimited = (bool) ($input['unlimited'] ?? false);

            PlanVersionFeature::query()->create([
                'plan_version_id' => $version->id,
                'feature_id' => $features[$key->value]->id,
                'enabled' => $enabled,
                // NULL = unlimited for limit features (see the plan catalog).
                'limit_value' => $enabled && $quantified && ! $unlimited ? max(0, (int) ($input['limit'] ?? 0)) : null,
                'config' => ! empty($input['config']) ? $input['config'] : null,
            ]);
        }

        return $version;
    }
}
