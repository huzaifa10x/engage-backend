<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Identity\Enums\PlatformRole;
use App\Domain\Identity\Models\PlatformAdmin;
use App\Domain\Identity\TwoFactor\Totp;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AdminAuthTest extends TestCase
{
    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/Login'));
    }

    public function test_password_alone_never_signs_in(): void
    {
        $admin = $this->createAdmin();

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'Secret-Passw0rd'])
            ->assertRedirect('/admin/two-factor/challenge');

        $this->assertGuest('admin');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $admin = $this->createAdmin();

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'nope'])->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    }

    public function test_first_login_requires_enrolment_then_shows_recovery_codes_once(): void
    {
        $admin = $this->createAdmin(withTwoFactor: false);

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'Secret-Passw0rd'])
            ->assertRedirect('/admin/two-factor/setup');

        $this->get('/admin/two-factor/setup')->assertInertia(fn (Assert $page) => $page->component('auth/TwoFactorSetup')->has('otpauth_uri'));
        $secret = (string) session('admin.2fa.setup_secret');

        $this->post('/admin/two-factor/setup', ['code' => Totp::codeAt($secret, Totp::currentStep())])
            ->assertRedirect('/admin/two-factor/recovery-codes');

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertTrue($admin->fresh()->hasTwoFactor());

        $this->get('/admin/two-factor/recovery-codes')->assertInertia(fn (Assert $page) => $page->component('auth/RecoveryCodes')->has('codes', 8));
        $this->get('/admin/two-factor/recovery-codes')->assertRedirect('/admin');
    }

    public function test_totp_challenge_signs_in_and_rejects_replay(): void
    {
        $admin = $this->createAdmin();
        $code = Totp::codeAt((string) $admin->two_factor_secret, Totp::currentStep());

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'Secret-Passw0rd']);
        $this->post('/admin/two-factor/challenge', ['code' => $code])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin, 'admin');

        $this->post('/admin/logout');
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'Secret-Passw0rd']);
        $this->post('/admin/two-factor/challenge', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest('admin');
    }

    public function test_recovery_code_works_exactly_once(): void
    {
        $admin = $this->createAdmin();

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'Secret-Passw0rd']);
        $this->post('/admin/two-factor/challenge', ['recovery_code' => 'aaaaaa-bbbbbb'])->assertRedirect('/admin');
        $this->assertSame(['cccccc-dddddd'], $admin->fresh()->two_factor_recovery_codes);

        $this->post('/admin/logout');
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'Secret-Passw0rd']);
        $this->post('/admin/two-factor/challenge', ['recovery_code' => 'aaaaaa-bbbbbb'])->assertSessionHasErrors('recovery_code');
    }

    public function test_disabled_admin_cannot_sign_in(): void
    {
        $admin = $this->createAdmin();
        $admin->forceFill(['disabled_at' => now()])->save();

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'Secret-Passw0rd'])->assertSessionHasErrors('email');
    }

    public function test_client_user_session_does_not_grant_admin_access(): void
    {
        $member = $this->addMember($this->createTenant());
        $this->actingAs($member->user, 'web');

        $this->get('/admin/companies')->assertRedirect('/admin/login');
    }

    public function test_platform_roles_are_enforced(): void
    {
        $this->actingAsAdmin(PlatformRole::Finance);

        $this->get('/admin/subscriptions')->assertOk();
        $this->get('/admin/team')->assertForbidden();
        $this->get('/admin/users')->assertForbidden();
    }

    public function test_last_super_admin_cannot_be_demoted(): void
    {
        $admin = $this->actingAsAdmin();

        $this->patch("/admin/team/{$admin->id}", ['role' => 'support', 'active' => true])->assertSessionHasErrors('role');
        $this->assertTrue(PlatformAdmin::query()->find($admin->id)?->isSuperAdmin());
    }
}
