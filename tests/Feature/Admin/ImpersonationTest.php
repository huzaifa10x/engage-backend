<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\PlatformAdmin;
use App\Domain\Platform\Models\ImpersonationSession;
use App\Domain\Tenancy\Models\TenantMembership;
use Tests\TestCase;

final class ImpersonationTest extends TestCase
{
    private const SPA = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];

    /** @return array{0: string, 1: TenantMembership, 2: PlatformAdmin} */
    private function startSupportSession(): array
    {
        $admin = $this->actingAsAdmin();
        $tenant = $this->createTenant(['name' => 'Nova Fitness']);
        $owner = $this->addMember($tenant);

        $response = $this->post("/admin/companies/{$tenant->id}/impersonate", [
            'membership_id' => $owner->id, 'reason' => 'Ticket #1042 templates not syncing',
        ]);

        $response->assertRedirect();
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        return [(string) $query['token'], $owner, $admin];
    }

    public function test_admin_can_log_in_as_a_member_and_actions_are_attributed_to_the_admin(): void
    {
        [$token, $owner, $admin] = $this->startSupportSession();

        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/impersonation', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.user.id', $owner->user_id)
            ->assertJsonPath('data.active_tenant_id', $owner->tenant_id)
            ->assertJsonPath('data.impersonation.admin_name', $admin->name);

        $this->withHeaders(self::SPA)->patchJson('/api/v1/tenant', ['name' => 'Nova Fitness JLT'])->assertOk();

        $entry = $this->tenantContext()->bypass(fn () => AuditLog::query()->where('action', 'tenant.updated')->first());
        $this->assertSame('admin', $entry?->getAttribute('actor_type'));
        $this->assertSame($admin->id, $entry?->getAttribute('actor_id'));
        $this->assertSame($owner->user_id, $entry?->getAttribute('meta')['impersonated_user_id'] ?? null);
    }

    public function test_token_is_single_use(): void
    {
        [$token] = $this->startSupportSession();

        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/impersonation', ['token' => $token])->assertOk();
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/impersonation', ['token' => $token])
            ->assertUnauthorized()->assertJsonPath('error.code', 'impersonation_invalid');
    }

    public function test_workspace_switching_is_blocked_and_stop_ends_the_session(): void
    {
        [$token, $owner] = $this->startSupportSession();
        $other = $this->createTenant();
        $this->addMember($other, user: $owner->user);

        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/impersonation', ['token' => $token])->assertOk();

        $this->withHeaders(self::SPA)->putJson('/api/v1/me/active-tenant', ['tenant_id' => $other->id])
            ->assertForbidden()->assertJsonPath('error.code', 'impersonation_active');

        $this->withHeaders(self::SPA)->deleteJson('/api/v1/auth/impersonation')->assertNoContent();

        $session = $this->tenantContext()->bypass(fn () => ImpersonationSession::query()->first());
        $this->assertNotNull($session?->ended_at);
    }

    public function test_expired_support_session_logs_out_instead_of_continuing_as_the_customer(): void
    {
        [$token] = $this->startSupportSession();
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/impersonation', ['token' => $token])->assertOk();

        $this->tenantContext()->bypass(fn () => ImpersonationSession::query()->update(['expires_at' => now()->subMinute()]));

        $this->withHeaders(self::SPA)->getJson('/api/v1/tenant')
            ->assertUnauthorized()->assertJsonPath('error.code', 'impersonation_invalid');
    }
}
