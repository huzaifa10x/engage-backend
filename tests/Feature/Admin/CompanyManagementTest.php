<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Enums\PlatformRole;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Plans\Models\Plan;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class CompanyManagementTest extends TestCase
{
    public function test_overview_and_company_pages_render_across_tenants(): void
    {
        $this->actingAsAdmin();
        $a = $this->createTenant(['name' => 'Nova Fitness']);
        $this->createTenant(['name' => 'Gulf Motors']);
        $this->addMember($a);
        $this->subscribe($a, 'growth');

        $this->get('/admin')->assertInertia(fn (Assert $page) => $page
            ->component('Overview')
            ->where('kpis.companies', 2)
            ->where('kpis.mrr_minor', 7900)
            ->has('partnerEligibility', 4));

        $this->get('/admin/companies?q=nova')->assertInertia(fn (Assert $page) => $page
            ->component('companies/Index')
            ->has('companies.data', 1)
            ->where('companies.data.0.plan', 'Growth'));

        $this->get("/admin/companies/{$a->id}")->assertInertia(fn (Assert $page) => $page
            ->component('companies/Show')
            ->where('company.name', 'Nova Fitness')
            ->where('subscription.plan_key', 'growth')
            ->has('members', 1)
            ->has('features', count(FeatureKey::cases())));
    }

    public function test_change_plan_updates_entitlements_and_is_audited_as_the_admin(): void
    {
        $admin = $this->actingAsAdmin();
        $tenant = $this->createTenant();
        $version = Plan::byKey('pro')->activeVersionOrFail();

        $this->put("/admin/companies/{$tenant->id}/plan", [
            'plan_version_id' => $version->id, 'status' => 'active', 'reason' => 'Signed Pro contract',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(15, $this->app->make(EntitlementService::class)->for($tenant)->get(FeatureKey::TeamSeats)->limit);

        $entry = $this->tenantContext()->bypass(fn () => AuditLog::query()->where('action', 'tenant.plan_changed')->first());
        $this->assertSame('admin', $entry?->getAttribute('actor_type'));
        $this->assertSame($admin->id, $entry?->getAttribute('actor_id'));
        $this->assertSame($tenant->id, $entry?->tenant_id);
    }

    public function test_suspension_blocks_the_client_api_immediately(): void
    {
        $this->actingAsAdmin();
        $tenant = $this->createTenant();
        $owner = $this->addMember($tenant);

        $this->patch("/admin/companies/{$tenant->id}/status", ['status' => 'suspended', 'reason' => 'Spam complaints'])->assertRedirect();

        $this->actingAsMember($owner)->getJson('/api/v1/tenant')
            ->assertForbidden()->assertJsonPath('error.code', 'tenant_unavailable');
    }

    public function test_entitlement_override_applies_and_can_be_removed(): void
    {
        $this->actingAsAdmin();
        $tenant = $this->createTenant(); // Free: 1 seat
        $service = $this->app->make(EntitlementService::class);

        $this->put("/admin/companies/{$tenant->id}/overrides/team_seats", ['limit' => 25, 'reason' => 'Design partner'])->assertRedirect();
        $this->assertSame(25, $service->for($tenant)->get(FeatureKey::TeamSeats)->limit);

        $this->delete("/admin/companies/{$tenant->id}/overrides/team_seats", ['reason' => 'Partnership ended'])->assertRedirect();
        $this->assertSame(1, $service->for($tenant)->get(FeatureKey::TeamSeats)->limit);
    }

    public function test_trial_can_be_extended(): void
    {
        $this->actingAsAdmin();
        $tenant = $this->createTenant();
        $this->put("/admin/companies/{$tenant->id}/plan", [
            'plan_version_id' => Plan::byKey('pro')->activeVersionOrFail()->id, 'status' => 'trialing', 'trial_days' => 14, 'reason' => 'Sales-led trial',
        ]);

        $this->post("/admin/companies/{$tenant->id}/trial", ['days' => 7, 'reason' => 'Waiting on Meta verification'])->assertSessionHas('success');

        $ends = $this->tenantContext()->bypass(fn () => $tenant->liveSubscription()->first()?->trial_ends_at);
        $this->assertTrue($ends->between(now()->addDays(20), now()->addDays(22)));
    }

    public function test_support_role_can_view_but_not_manage(): void
    {
        $this->actingAsAdmin(PlatformRole::Support);
        $tenant = $this->createTenant();

        $this->get("/admin/companies/{$tenant->id}")->assertOk();
        $this->patch("/admin/companies/{$tenant->id}/status", ['status' => 'suspended', 'reason' => 'Should not work'])->assertForbidden();
    }

    public function test_disabling_a_user_blocks_their_api_access(): void
    {
        $this->actingAsAdmin();
        $owner = $this->addMember($this->createTenant());

        $this->patch("/admin/users/{$owner->user_id}/status", ['active' => false, 'reason' => 'Account compromised'])->assertRedirect();

        $this->actingAsMember($owner->setRelation('user', $owner->user->fresh()))->getJson('/api/v1/me')->assertUnauthorized();
    }
}
