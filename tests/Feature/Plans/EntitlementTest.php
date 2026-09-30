<?php

declare(strict_types=1);

namespace Tests\Feature\Plans;

use App\Application\Tenancy\RegisterWorkspace;
use App\Domain\Billing\Enums\SubscriptionStatus;
use App\Domain\Billing\Models\Subscription;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Plans\Models\Feature;
use App\Domain\Plans\Models\TenantEntitlementOverride;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class EntitlementTest extends TestCase
{
    public function test_new_workspace_starts_on_a_14_day_pro_trial(): void
    {
        $result = $this->app->make(RegisterWorkspace::class)([
            'name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'Secret123456', 'company_name' => 'Acme',
        ]);

        $set = $this->app->make(EntitlementService::class)->for($result['tenant']);

        $this->assertSame('pro', $set->planKey);
        $this->assertSame('trialing', $set->subscriptionStatus);
        $this->assertSame(15, $set->get(FeatureKey::TeamSeats)->limit);
        $this->assertTrue($set->allows(FeatureKey::ApiAccess));
    }

    public function test_expired_trial_downgrades_to_free(): void
    {
        $result = $this->app->make(RegisterWorkspace::class)([
            'name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'Secret123456', 'company_name' => 'Acme',
        ]);

        $this->tenantContext()->bypass(fn () => Subscription::query()->where('tenant_id', $result['tenant']->id)
            ->update(['trial_ends_at' => now()->subMinute()]));

        Artisan::call('engage:subscriptions:expire-trials');

        $set = $this->app->make(EntitlementService::class)->for($result['tenant']);

        $this->assertSame('free', $set->planKey);
        $this->assertSame(SubscriptionStatus::Active->value, $set->subscriptionStatus);
        $this->assertSame(1, $set->get(FeatureKey::TeamSeats)->limit);
        $this->assertFalse($set->allows(FeatureKey::Broadcasts));
    }

    public function test_override_can_make_a_limit_unlimited(): void
    {
        $tenant = $this->createTenant();
        $service = $this->app->make(EntitlementService::class);

        $this->assertSame(1, $service->for($tenant)->get(FeatureKey::TeamSeats)->limit);

        $this->tenantContext()->bypass(fn () => TenantEntitlementOverride::query()->create([
            'tenant_id' => $tenant->id,
            'feature_id' => Feature::query()->where('key', 'team_seats')->value('id'),
            'unlimited' => true,
            'reason' => 'Design partner',
        ]));
        $service->forget($tenant);

        $this->assertTrue($service->for($tenant)->get(FeatureKey::TeamSeats)->isUnlimited());
    }

    public function test_tenants_cannot_write_their_own_overrides(): void
    {
        $tenant = $this->createTenant();

        $this->expectException(QueryException::class);

        $this->tenantContext()->run($tenant, fn () => TenantEntitlementOverride::query()->create([
            'feature_id' => Feature::query()->where('key', 'team_seats')->value('id'),
            'unlimited' => true,
        ]));
    }
}
