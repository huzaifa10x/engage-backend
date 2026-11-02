<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Growth\GrowthSettings;
use App\Domain\Identity\Enums\PlatformRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Growth Hub is a per-workspace pilot switch, not part of any plan; limits have no defaults. */
final class GrowthHubSwitchTest extends TestCase
{
    public function test_super_admin_turns_growth_hub_on_and_the_start_time_never_moves(): void
    {
        $this->actingAsAdmin();
        $tenant = $this->createTenant();
        $this->subscribe($tenant, 'growth'); // the "Growth" plan does not include Growth Hub

        $this->get("/admin/companies/{$tenant->id}")->assertInertia(fn (Assert $page) => $page
            ->where('growth.enabled', false)
            ->where('growth.started_at', null)
            ->where('growth.max_sites', null)
            ->where('growth.max_accepted_events_per_calendar_month', null));

        $payload = ['enabled' => true, 'strip_reference_before_flows' => false, 'max_sites' => 2, 'max_accepted_events_per_calendar_month' => null,
            'detailed_history_days' => null, 'aggregate_history_days' => null, 'reason' => 'Pilot client'];
        $this->put("/admin/companies/{$tenant->id}/growth", $payload)->assertRedirect()->assertSessionHas('success');

        $settings = GrowthSettings::for($this->tenantContext()->bypass(fn () => $tenant->refresh()));
        $this->assertTrue($settings->enabled);
        $this->assertNotNull($startedAt = $settings->startedAt);
        $this->assertSame(2, $settings->toArray()['max_sites']);

        $this->travel(2)->days();
        $this->put("/admin/companies/{$tenant->id}/growth", ['enabled' => false] + $payload)->assertRedirect();
        $this->put("/admin/companies/{$tenant->id}/growth", $payload)->assertRedirect();

        GrowthSettings::forget();
        $this->assertTrue($startedAt->equalTo(GrowthSettings::for($this->tenantContext()->bypass(fn () => $tenant->refresh()))->startedAt));
        $this->assertSame(3, $this->tenantContext()->bypass(fn () => AuditLog::query()->where('action', 'tenant.growth_changed')->count()));
    }

    public function test_a_reason_is_required_and_read_only_admins_cannot_switch_it(): void
    {
        $this->actingAsAdmin();
        $tenant = $this->createTenant();
        $this->put("/admin/companies/{$tenant->id}/growth", ['enabled' => true, 'strip_reference_before_flows' => false])->assertSessionHasErrors('reason');

        $this->actingAsAdmin(PlatformRole::Support);
        $this->put("/admin/companies/{$tenant->id}/growth", ['enabled' => true, 'strip_reference_before_flows' => false, 'reason' => 'Pilot client'])->assertForbidden();
    }
}
