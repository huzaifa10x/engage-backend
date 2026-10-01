<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Identity\Models\PlatformAdmin;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AccountCommandsTest extends TestCase
{
    /** Same SPA headers as AuthTest: login needs a stateful (session) request. */
    private const SPA = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];

    public function test_client_create_makes_user_workspace_and_can_log_in(): void
    {
        $this->artisan('engage:client:create', ['email' => 'Owner@Example.com', '--name' => 'Waqar', '--company' => '10X Digital'])
            ->expectsQuestion('Password (min 10, mixed case, numbers)', 'Str0ngPassw0rd')
            ->assertSuccessful();

        $user = User::query()->where('email', 'owner@example.com')->firstOrFail();
        $this->assertNotNull($user->getAttribute('last_active_tenant_id'));

        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login', ['email' => 'owner@example.com', 'password' => 'Str0ngPassw0rd'])
            ->assertOk()->assertJsonPath('data.memberships.0.tenant.name', '10X Digital');
    }

    public function test_client_create_rejects_weak_password_and_duplicates(): void
    {
        $this->artisan('engage:client:create', ['email' => 'a@example.com', '--name' => 'A', '--company' => 'A'])
            ->expectsQuestion('Password (min 10, mixed case, numbers)', 'Password')
            ->assertFailed();

        $this->artisan('engage:client:create', ['email' => 'a@example.com', '--name' => 'A', '--company' => 'A'])
            ->expectsQuestion('Password (min 10, mixed case, numbers)', 'Str0ngPassw0rd')->assertSuccessful();
        $this->artisan('engage:client:create', ['email' => 'a@example.com', '--name' => 'A', '--company' => 'B'])
            ->expectsQuestion('Password (min 10, mixed case, numbers)', 'Str0ngPassw0rd')->assertFailed();
    }

    public function test_client_password_reset(): void
    {
        $this->artisan('engage:client:create', ['email' => 'b@example.com', '--name' => 'B', '--company' => 'B'])
            ->expectsQuestion('Password (min 10, mixed case, numbers)', 'Str0ngPassw0rd')->assertSuccessful();

        $this->artisan('engage:client:password', ['email' => 'b@example.com'])
            ->expectsQuestion('New password (min 10, mixed case, numbers)', 'An0therPassw0rd')->assertSuccessful();

        $this->assertTrue(Hash::check('An0therPassw0rd', (string) User::query()->where('email', 'b@example.com')->value('password')));
    }

    public function test_admin_password_reset_can_clear_two_factor(): void
    {
        $admin = PlatformAdmin::query()->create(['name' => 'Ops', 'email' => 'ops@example.com', 'role' => 'super_admin', 'password' => 'Original123456']);
        $admin->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

        $this->artisan('engage:admin:password', ['email' => 'ops@example.com', '--reset-2fa' => true])
            ->expectsQuestion('New password (min 12, mixed case, numbers)', 'Brand0NewPassw0rd')->assertSuccessful();

        $fresh = $admin->fresh();
        $this->assertTrue(Hash::check('Brand0NewPassw0rd', (string) $fresh?->getAttribute('password')));
        $this->assertNull($fresh?->getAttribute('two_factor_confirmed_at'));

        $this->artisan('engage:admin:password', ['email' => 'nobody@example.com'])->assertFailed();
    }
}
