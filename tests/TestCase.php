<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\Access\Models\Role;
use App\Domain\Access\SystemRole;
use App\Domain\Billing\SubscriptionService;
use App\Domain\Identity\Enums\PlatformRole;
use App\Domain\Identity\Models\PlatformAdmin;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\TwoFactor\Totp;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Seeds system roles + plan catalog (demo data is local-only). */
    protected bool $seed = true;

    private static bool $roleVerified = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$roleVerified) {
            $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');

            if ($role->rolsuper || $role->rolbypassrls) {
                $this->fail('Tests must connect as a NON-superuser, NON-bypassrls role, otherwise RLS is silently skipped. See docker/postgres/init/01-engage.sql.');
            }

            self::$roleVerified = true;
        }

        $this->withoutVite();
    }

    protected function createAdmin(PlatformRole $role = PlatformRole::SuperAdmin, bool $withTwoFactor = true): PlatformAdmin
    {
        $admin = PlatformAdmin::query()->create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'Secret-Passw0rd',
            'role' => $role,
        ]);

        if ($withTwoFactor) {
            $admin->forceFill([
                'two_factor_secret' => Totp::generateSecret(),
                'two_factor_confirmed_at' => now(),
                'two_factor_recovery_codes' => ['aaaaaa-bbbbbb', 'cccccc-dddddd'],
            ])->save();
        }

        return $admin;
    }

    protected function actingAsAdmin(PlatformRole $role = PlatformRole::SuperAdmin): PlatformAdmin
    {
        $admin = $this->createAdmin($role);
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    protected function tenantContext(): TenantContext
    {
        return $this->app->make(TenantContext::class);
    }

    /** @param array<string, mixed> $attributes */
    protected function createTenant(array $attributes = []): Tenant
    {
        return $this->tenantContext()->bypass(fn () => Tenant::factory()->create($attributes));
    }

    protected function addMember(Tenant $tenant, SystemRole $role = SystemRole::Owner, ?User $user = null): TenantMembership
    {
        $user ??= User::factory()->create();

        $membership = $this->tenantContext()->bypass(fn () => TenantMembership::query()->create([
            'tenant_id' => $tenant->getKey(),
            'user_id' => $user->getKey(),
            'role_id' => Role::system($role)->getKey(),
            'status' => MembershipStatus::Active,
            'joined_at' => now(),
        ]));

        return $membership->setRelation('user', $user)->setRelation('tenant', $tenant);
    }

    protected function subscribe(Tenant $tenant, string $planKey): void
    {
        $this->app->make(SubscriptionService::class)->assign($tenant, Plan::byKey($planKey)->activeVersionOrFail());
    }

    /** Token-style auth (no session): ResolveTenant falls back to users.last_active_tenant_id. */
    protected function actingAsMember(TenantMembership $membership): static
    {
        /** @var User $user */
        $user = $membership->user;
        $user->forceFill(['last_active_tenant_id' => $membership->tenant_id])->save();

        Sanctum::actingAs($user);

        return $this;
    }
}
