<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Access\Models\Role;
use App\Domain\Access\SystemRole;
use App\Domain\Identity\Models\User;
use App\Notifications\TenantInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TeamTest extends TestCase
{
    public function test_agent_cannot_invite(): void
    {
        $tenant = $this->createTenant();
        $this->subscribe($tenant, 'pro');
        $agent = $this->addMember($tenant, SystemRole::Agent);

        $this->actingAsMember($agent)
            ->postJson('/api/v1/team/invitations', ['email' => 'new@example.com', 'role_id' => Role::system(SystemRole::Agent)->id])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_free_plan_seat_limit_returns_402(): void
    {
        $owner = $this->addMember($this->createTenant()); // no subscription → Free (1 seat)

        $this->actingAsMember($owner)
            ->postJson('/api/v1/team/invitations', ['email' => 'new@example.com', 'role_id' => Role::system(SystemRole::Agent)->id])
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'plan_limit_reached')
            ->assertJsonPath('error.details.feature', 'team_seats');
    }

    public function test_invite_and_accept_flow(): void
    {
        Notification::fake();

        $tenant = $this->createTenant();
        $this->subscribe($tenant, 'pro');
        $owner = $this->addMember($tenant);

        $this->actingAsMember($owner)
            ->postJson('/api/v1/team/invitations', ['email' => 'New.Agent@Example.com', 'role_id' => Role::system(SystemRole::Agent)->id])
            ->assertCreated()
            ->assertJsonPath('data.email', 'new.agent@example.com');

        $token = null;
        Notification::assertSentOnDemand(TenantInvitationNotification::class, function (TenantInvitationNotification $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $invitee = User::factory()->create(['email' => 'new.agent@example.com']);
        Sanctum::actingAs($invitee);

        $this->postJson("/api/v1/invitations/{$token}/accept")
            ->assertOk()
            ->assertJsonPath('data.role.key', 'agent');

        // Token is single-use.
        $this->postJson("/api/v1/invitations/{$token}/accept")
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'invitation_invalid');
    }

    public function test_last_owner_cannot_be_demoted(): void
    {
        $owner = $this->addMember($this->createTenant());

        $this->actingAsMember($owner)
            ->patchJson("/api/v1/team/members/{$owner->id}", ['role_id' => Role::system(SystemRole::Admin)->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'last_owner');
    }

    public function test_admin_cannot_remove_an_owner(): void
    {
        $tenant = $this->createTenant();
        $this->subscribe($tenant, 'pro');
        $owner = $this->addMember($tenant);
        $admin = $this->addMember($tenant, SystemRole::Admin);

        $this->actingAsMember($admin)
            ->deleteJson("/api/v1/team/members/{$owner->id}")
            ->assertForbidden();
    }

    public function test_actions_are_audited(): void
    {
        $tenant = $this->createTenant();
        $this->subscribe($tenant, 'pro');
        $owner = $this->addMember($tenant);
        $agent = $this->addMember($tenant, SystemRole::Agent);

        $this->actingAsMember($owner)
            ->patchJson("/api/v1/team/members/{$agent->id}", ['role_id' => Role::system(SystemRole::Viewer)->id])
            ->assertOk();

        $this->actingAsMember($owner)
            ->getJson('/api/v1/audit-logs?action=membership.role_changed')
            ->assertOk()
            ->assertJsonPath('data.0.after.role', 'viewer')
            ->assertJsonPath('data.0.actor.id', $owner->user_id);
    }
}
