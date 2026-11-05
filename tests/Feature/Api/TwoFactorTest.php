<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Access\SystemRole;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\TwoFactor\Totp;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Two-factor sign-in (authenticator app) for the portal: required for workspace Owners, optional
 * for everyone else; asked on every sign-in in place of the emailed code; recovery codes work once.
 */
final class TwoFactorTest extends TestCase
{
    private const SPA = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];

    private const PASSWORD = 'Secret-Passw0rd';

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['engage.auth.owner_two_factor_required' => true]);
        $this->withCredentials();
        Notification::fake();
        $this->tenant = $this->createTenant();
        $this->owner = $this->addMember($this->tenant)->user()->firstOrFail();
        $this->owner->forceFill(['password' => self::PASSWORD, 'last_active_tenant_id' => $this->tenant->id, 'email_verified_at' => now()])->save();
    }

    private function spaGet(string $path): TestResponse
    {
        return $this->withHeaders(self::SPA)->getJson($path);
    }

    private function spaPost(string $path, array $body = []): TestResponse
    {
        return $this->withHeaders(self::SPA)->postJson($path, $body);
    }

    /** Turns two-factor on for the signed-in user; returns [secret, recovery codes]. */
    private function enroll(): array
    {
        $secret = str_replace(' ', '', (string) $this->spaPost('/api/v1/auth/two-factor/setup')->assertOk()->json('data.secret'));
        $codes = $this->spaPost('/api/v1/auth/two-factor/confirm', ['code' => Totp::codeAt($secret, Totp::currentStep())])->assertOk()
            ->assertJsonPath('data.enabled', true)->assertJsonCount(10, 'data.recovery_codes')->json('data.recovery_codes');

        return [$secret, $codes];
    }

    private function signOut(): void
    {
        $this->spaPost('/api/v1/auth/logout');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
    }

    public function test_an_owner_must_turn_it_on_before_using_the_workspace(): void
    {
        $this->actingAs($this->owner);
        $this->spaGet('/api/v1/me')->assertOk()->assertJsonPath('data.two_factor.required', true)->assertJsonPath('data.two_factor.enabled', false);
        $this->spaGet('/api/v1/tenant')->assertStatus(403)->assertJsonPath('error.code', 'two_factor_setup_required');

        $this->spaPost('/api/v1/auth/two-factor/setup')->assertOk()->assertJsonStructure(['data' => ['secret', 'otpauth_uri']]);
        $this->spaPost('/api/v1/auth/two-factor/confirm', ['code' => '000000'])->assertStatus(422);
        $this->enroll();
        $this->spaGet('/api/v1/tenant')->assertOk();

        // Required for Owners: it cannot be turned off.
        $this->withHeaders(self::SPA)->deleteJson('/api/v1/auth/two-factor', ['code' => '123456'])->assertStatus(422);

    }

    public function test_other_roles_are_not_required_to(): void
    {
        $agent = $this->addMember($this->tenant, SystemRole::Agent);
        $agent->user()->firstOrFail()->forceFill(['email_verified_at' => now()])->save();
        $this->actingAsMember($agent);
        $this->spaGet('/api/v1/tenant')->assertOk();
        $this->spaGet('/api/v1/auth/two-factor')->assertOk()->assertJsonPath('data.required', false)->assertJsonPath('data.enabled', false);
    }

    public function test_sign_in_asks_for_the_app_code_or_a_recovery_code_once(): void
    {
        $this->actingAs($this->owner);
        [$secret, $codes] = $this->enroll();
        $this->signOut();

        $login = fn () => $this->spaPost('/api/v1/auth/login', ['email' => $this->owner->email, 'password' => self::PASSWORD]);
        $login()->assertStatus(202)->assertJsonPath('data.two_factor_required', true)->assertJsonMissingPath('data.user');
        Notification::assertNothingSent(); // the app code replaces the emailed code
        $this->spaGet('/api/v1/me')->assertStatus(401);

        $this->spaPost('/api/v1/auth/login/two-factor', ['code' => '000000'])->assertStatus(422);
        // The code used to turn it on cannot be replayed…
        $this->spaPost('/api/v1/auth/login/two-factor', ['code' => Totp::codeAt($secret, Totp::currentStep())])->assertStatus(422);
        // …the next one (30 seconds later) works.
        $this->owner->refresh()->forceFill(['two_factor_last_used_step' => Totp::currentStep() - 1])->save();
        $this->spaPost('/api/v1/auth/login/two-factor', ['code' => Totp::codeAt($secret, Totp::currentStep())])->assertOk()
            ->assertJsonPath('data.user.email', $this->owner->email);
        $this->spaGet('/api/v1/tenant')->assertOk();

        // A recovery code works once.
        $this->signOut();
        $login()->assertStatus(202);
        $this->spaPost('/api/v1/auth/login/two-factor', ['recovery_code' => $codes[0]])->assertOk();
        $this->signOut();
        $login()->assertStatus(202);
        $this->spaPost('/api/v1/auth/login/two-factor', ['recovery_code' => $codes[0]])->assertStatus(422);
        $this->spaPost('/api/v1/auth/login/two-factor', ['recovery_code' => $codes[1]])->assertOk();
        $this->spaGet('/api/v1/auth/two-factor')->assertOk()->assertJsonPath('data.recovery_codes_left', 8);
    }
}
