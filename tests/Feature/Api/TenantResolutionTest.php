<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Access\SystemRole;
use App\Domain\Identity\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TenantResolutionTest extends TestCase
{
    public function test_members_list_only_contains_the_active_tenant(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();
        $owner = $this->addMember($a);
        $this->addMember($a, SystemRole::Agent);
        $this->addMember($b);
        $this->addMember($b, user: $owner->user); // same user, second workspace

        $this->actingAsMember($owner)
            ->getJson('/api/v1/team/members')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_another_tenants_record_id_is_a_404(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();
        $owner = $this->addMember($a);
        $foreign = $this->addMember($b, SystemRole::Agent);

        $this->actingAsMember($owner)
            ->deleteJson("/api/v1/team/members/{$foreign->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_switching_to_a_tenant_without_membership_is_forbidden(): void
    {
        $owner = $this->addMember($this->createTenant());
        $stranger = $this->createTenant();

        $this->actingAsMember($owner)
            ->putJson('/api/v1/me/active-tenant', ['tenant_id' => $stranger->id])
            ->assertForbidden();
    }

    public function test_ambiguous_tenant_requires_selection(): void
    {
        $membership = $this->addMember($this->createTenant());
        $this->addMember($this->createTenant(), user: $membership->user);

        $membership->user->forceFill(['last_active_tenant_id' => null])->save();
        Sanctum::actingAs($membership->user);

        $this->getJson('/api/v1/tenant')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'tenant_selection_required');
    }

    public function test_user_without_membership_gets_tenant_required(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/tenant')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'tenant_required');
    }

    public function test_suspended_tenant_is_blocked(): void
    {
        $owner = $this->addMember($this->createTenant(['status' => 'suspended']));

        $this->actingAsMember($owner)
            ->getJson('/api/v1/tenant')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'tenant_unavailable');
    }

    public function test_every_response_carries_a_request_id(): void
    {
        $owner = $this->addMember($this->createTenant());

        $this->actingAsMember($owner)->getJson('/api/v1/tenant')->assertOk()->assertHeader('X-Request-Id');
    }
}
