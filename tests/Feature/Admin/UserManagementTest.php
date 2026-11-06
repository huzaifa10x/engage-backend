<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Notifications\AccountChangedBySupportNotification;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Super Admin › Users › a user: details, email, password, verification and sessions. */
final class UserManagementTest extends TestCase
{
    private const SPA = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $tenant = $this->createTenant();
        $this->user = $this->addMember($tenant)->user()->firstOrFail();
        $this->user->forceFill(['name' => 'Rabab', 'email' => 'rabab@example.com', 'password' => 'Old-Passw0rd!', 'email_verified_at' => now(), 'last_active_tenant_id' => $tenant->id])->save();
    }

    private function audited(string $action): bool
    {
        return $this->tenantContext()->bypass(fn () => AuditLog::query()->where('action', $action)->where('entity_id', $this->user->id)->exists());
    }

    private function signIn(string $password): TestResponse
    {
        config(['engage.login_otp.enabled' => false, 'engage.auth.owner_two_factor_required' => false]);
        $this->app['auth']->forgetGuards();

        return $this->withCredentials()->withHeaders(self::SPA)->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => $password]);
    }

    public function test_the_user_page_shows_details_workspaces_and_activity(): void
    {
        $this->actingAsAdmin();
        $this->get("/admin/users/{$this->user->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('users/Show')->where('user.email', 'rabab@example.com')->has('memberships', 1)->has('audit'));
    }

    public function test_details_and_email_are_changed_and_both_addresses_are_told(): void
    {
        $other = $this->addMember($this->createTenant())->user()->firstOrFail();
        $this->actingAsAdmin();

        $this->patch("/admin/users/{$this->user->id}", ['name' => 'X', 'email' => strtoupper((string) $other->email), 'email_verified' => true])
            ->assertSessionHasErrors('email');

        $this->patch("/admin/users/{$this->user->id}", ['name' => 'Rabab Khan', 'email' => 'Rabab.Khan@Example.com', 'timezone' => 'Asia/Dubai', 'email_verified' => false])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->user->refresh();
        $this->assertSame(['Rabab Khan', 'rabab.khan@example.com', 'Asia/Dubai'], [$this->user->name, $this->user->email, $this->user->getAttribute('timezone')]);
        $this->assertNull($this->user->getAttribute('email_verified_at'));
        $this->assertTrue($this->audited('user.updated_by_support'));
        Notification::assertSentTo($this->user, AccountChangedBySupportNotification::class);
        Notification::assertSentTo(new AnonymousNotifiable, AccountChangedBySupportNotification::class,
            fn ($n, $channels, AnonymousNotifiable $to) => $to->routes['mail'] === 'rabab@example.com');

        $this->post("/admin/users/{$this->user->id}/verify-email")->assertRedirect();
        $this->assertNotNull($this->user->refresh()->getAttribute('email_verified_at'));
    }

    public function test_a_password_set_by_support_works_and_ends_every_session(): void
    {
        $this->signIn('Old-Passw0rd!')->assertOk();
        $this->withHeaders(self::SPA)->getJson('/api/v1/me')->assertOk();
        $session = $this->app['session']->all();

        $this->actingAsAdmin();
        $this->put("/admin/users/{$this->user->id}/password", ['password' => 'weak', 'reason' => 'Forgot password, verified by call'])->assertSessionHasErrors('password');
        $this->put("/admin/users/{$this->user->id}/password", ['password' => 'New-Passw0rd-2026', 'reason' => 'Forgot password, verified by call'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('New-Passw0rd-2026', (string) $this->user->refresh()->password));
        $this->assertTrue($this->audited('user.password_set_by_support'));
        Notification::assertSentTo($this->user, AccountChangedBySupportNotification::class, fn ($n) => $n->change === AccountChangedBySupportNotification::PASSWORD);

        // The old browser session is over; the old password no longer works, the new one does.
        $this->app['auth']->forgetGuards();
        $this->withSession($session)->withHeaders(self::SPA)->getJson('/api/v1/me')->assertUnauthorized();
        $this->flushSession();
        $this->travel(2)->seconds();
        $this->signIn('Old-Passw0rd!')->assertStatus(422);
        $this->signIn('New-Passw0rd-2026')->assertOk();
        $this->withHeaders(self::SPA)->getJson('/api/v1/me')->assertOk();
    }

    public function test_sign_out_everywhere_and_reset_link(): void
    {
        $this->signIn('Old-Passw0rd!')->assertOk();
        $session = $this->app['session']->all();

        $this->actingAsAdmin();
        $this->post("/admin/users/{$this->user->id}/sign-out", ['reason' => 'Lost laptop'])->assertRedirect();
        $this->assertTrue($this->audited('user.signed_out_by_support'));
        $this->app['auth']->forgetGuards();
        $this->withSession($session)->withHeaders(self::SPA)->getJson('/api/v1/me')->assertUnauthorized();

        // Signing in again afterwards works (same password).
        $this->flushSession();
        $this->travel(2)->seconds();
        $this->signIn('Old-Passw0rd!')->assertOk();
        $this->withHeaders(self::SPA)->getJson('/api/v1/me')->assertOk();

        $this->actingAsAdmin();
        $this->post("/admin/users/{$this->user->id}/password-reset")->assertRedirect()->assertSessionHasNoErrors();
        Notification::assertSentTo($this->user, ResetPasswordNotification::class);
        $this->assertTrue($this->audited('user.password_reset_link_sent_by_support'));
    }
}
