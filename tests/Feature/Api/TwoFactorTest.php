<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Access\SystemRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\TwoFactor\Totp;
use App\Domain\Tenancy\Models\Tenant;
use App\Notifications\LoginCodeNotification;
use App\Notifications\TwoFactorChangedNotification;
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
        Notification::assertNotSentTo($this->owner, LoginCodeNotification::class); // the app code replaces the emailed code
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

    /** The code the app shows now, with the last used one moved back so a fresh code is accepted. */
    private function freshCode(string $secret): string
    {
        $this->owner->refresh()->forceFill(['two_factor_last_used_step' => Totp::currentStep() - 1])->save();

        return Totp::codeAt($secret, Totp::currentStep());
    }

    private function replaceStarted(array $proof): string
    {
        return str_replace(' ', '', (string) $this->spaPost('/api/v1/auth/two-factor/replace', $proof)->assertOk()
            ->assertJsonStructure(['data' => ['secret', 'otpauth_uri']])->json('data.secret'));
    }

    private function audited(string $action, ?string $via = null): bool
    {
        return $this->tenantContext()->bypass(fn () => AuditLog::query()->where('action', $action)
            ->where('entity_id', $this->owner->id)->when($via, fn ($q) => $q->where('meta->verified_with', $via))->exists());
    }

    private function sent(string $change): void
    {
        Notification::assertSentTo($this->owner, TwoFactorChangedNotification::class, fn (TwoFactorChangedNotification $n) => $n->change === $change);
    }

    public function test_the_authenticator_is_replaced_only_after_the_new_one_is_confirmed(): void
    {
        $this->actingAs($this->owner);
        [$old, $oldCodes] = $this->enroll();
        $this->sent(TwoFactorChangedNotification::ENABLED);
        $this->spaPost('/api/v1/auth/two-factor/setup')->assertStatus(422); // already on: replace, not set up again

        // The current factor is needed first.
        $this->spaPost('/api/v1/auth/two-factor/replace')->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['code']]]]);
        $this->spaPost('/api/v1/auth/two-factor/replace', ['code' => '000000'])->assertStatus(422);
        $new = $this->replaceStarted(['code' => $this->freshCode($old)]);
        $this->assertNotSame($old, $new);

        // Half-way: the old authenticator still works, a code from it does not confirm the new one.
        $this->assertSame($old, $this->owner->refresh()->getAttribute('two_factor_secret'));
        $this->spaPost('/api/v1/auth/two-factor/confirm', ['code' => Totp::codeAt($old, Totp::currentStep())])->assertStatus(422);
        $this->assertSame($old, $this->owner->refresh()->getAttribute('two_factor_secret'));

        $newCodes = $this->spaPost('/api/v1/auth/two-factor/confirm', ['code' => Totp::codeAt($new, Totp::currentStep())])->assertOk()
            ->assertJsonPath('data.enabled', true)->assertJsonCount(10, 'data.recovery_codes')->json('data.recovery_codes');
        $this->assertSame($new, $this->owner->refresh()->getAttribute('two_factor_secret'));
        $this->assertSame([], array_intersect($oldCodes, $newCodes));
        $this->assertTrue($this->audited('user.two_factor_replaced', 'app'));
        $this->sent(TwoFactorChangedNotification::REPLACED);

        // Signing in now takes the new app; the old app and the old recovery codes no longer work.
        $this->signOut();
        $this->spaPost('/api/v1/auth/login', ['email' => $this->owner->email, 'password' => self::PASSWORD])->assertStatus(202);
        $this->owner->refresh()->forceFill(['two_factor_last_used_step' => Totp::currentStep() - 1])->save();
        $this->spaPost('/api/v1/auth/login/two-factor', ['code' => Totp::codeAt($old, Totp::currentStep())])->assertStatus(422);
        $this->spaPost('/api/v1/auth/login/two-factor', ['recovery_code' => $oldCodes[0]])->assertStatus(422);
        $this->spaPost('/api/v1/auth/login/two-factor', ['code' => Totp::codeAt($new, Totp::currentStep())])->assertOk();
    }

    public function test_a_recovery_code_can_prove_the_current_factor_and_an_abandoned_replacement_expires(): void
    {
        $this->actingAs($this->owner);
        [$old, $codes] = $this->enroll();

        $this->replaceStarted(['recovery_code' => strtoupper($codes[3])]);
        $this->spaGet('/api/v1/auth/two-factor')->assertJsonPath('data.recovery_codes_left', 9);

        // Never confirmed: after 15 minutes it has to be started again, and the old app kept working throughout.
        $this->travel(16)->minutes();
        $this->spaPost('/api/v1/auth/two-factor/confirm', ['code' => '123456'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['fields' => ['code']]]]);
        $this->assertSame($old, $this->owner->refresh()->getAttribute('two_factor_secret'));
        $this->spaPost('/api/v1/auth/two-factor/confirm', ['code' => '123456'])->assertStatus(422); // nothing pending any more
        $this->spaPost('/api/v1/auth/two-factor/recovery-codes', ['code' => $this->freshCode($old)])->assertOk()->assertJsonCount(10, 'data.recovery_codes');
        $this->sent(TwoFactorChangedNotification::CODES_RENEWED);
    }

    public function test_a_lost_phone_signs_in_with_a_recovery_code_and_replaces_the_authenticator(): void
    {
        $this->actingAs($this->owner);
        [, $codes] = $this->enroll();
        $this->signOut();

        $this->spaPost('/api/v1/auth/login', ['email' => $this->owner->email, 'password' => self::PASSWORD])->assertStatus(202);
        $this->spaPost('/api/v1/auth/login/two-factor', ['recovery_code' => $codes[0]])->assertOk()->assertJsonPath('data.two_factor.recovery_sign_in', true);

        // Right after a recovery sign-in no second code is needed.
        $new = $this->replaceStarted([]);
        $this->spaPost('/api/v1/auth/two-factor/confirm', ['code' => Totp::codeAt($new, Totp::currentStep())])->assertOk();
        $this->spaGet('/api/v1/auth/two-factor')->assertJsonPath('data.recovery_sign_in', false)->assertJsonPath('data.recovery_codes_left', 10)
            ->assertJsonPath('data.enabled', true);
        $this->assertTrue($this->audited('user.two_factor_replaced', 'recovery_sign_in'));
    }

    public function test_a_super_admin_resets_it_when_phone_and_recovery_codes_are_lost(): void
    {
        $this->actingAs($this->owner);
        $this->enroll();
        $this->actingAsAdmin();
        $this->post("/admin/users/{$this->owner->id}/reset-two-factor", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/admin/users/{$this->owner->id}/reset-two-factor", ['reason' => 'Lost phone, verified by call'])->assertRedirect();

        $this->assertFalse($this->owner->refresh()->hasTwoFactor());
        $this->assertTrue($this->audited('user.two_factor_reset'));
        $this->sent(TwoFactorChangedNotification::RESET);

        // As an Owner they are asked to set up a new authenticator before using the workspace.
        $this->actingAs($this->owner, 'web');
        $this->spaGet('/api/v1/tenant')->assertStatus(403)->assertJsonPath('error.code', 'two_factor_setup_required');
        $this->enroll();
    }

    public function test_a_review_account_signs_in_with_email_and_password_only(): void
    {
        config(['engage.login_otp.enabled' => true]);
        $this->actingAs($this->owner);
        $this->enroll();
        $this->actingAsAdmin();
        $this->patch("/admin/users/{$this->owner->id}/review-account", ['enabled' => true, 'reason' => 'Meta App Review test account'])->assertRedirect();
        $this->assertTrue($this->owner->refresh()->isReviewAccount());
        $this->assertFalse($this->owner->hasTwoFactor()); // removed, so nothing asks for a code
        $this->assertTrue($this->audited('user.review_account_on'));

        // No authenticator and no emailed code, even as an Owner, and the workspace works.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->spaPost('/api/v1/auth/login', ['email' => $this->owner->email, 'password' => self::PASSWORD])->assertOk()
            ->assertJsonPath('data.user.email', $this->owner->email)->assertJsonPath('data.two_factor.required', false)
            ->assertJsonPath('data.two_factor.review_account', true);
        Notification::assertNotSentTo($this->owner, LoginCodeNotification::class);
        $this->spaGet('/api/v1/tenant')->assertOk();
        $this->spaPost('/api/v1/auth/two-factor/setup')->assertStatus(422);
        $this->assertTrue($this->audited('user.review_account_signed_in'));

        // Turned off: the usual checks are back.
        $this->actingAsAdmin();
        $this->patch("/admin/users/{$this->owner->id}/review-account", ['enabled' => false, 'reason' => 'App Review approved'])->assertRedirect();
        $this->assertFalse($this->owner->refresh()->isReviewAccount());
        $this->assertTrue($this->owner->twoFactorRequired());
    }

    public function test_a_lost_authenticator_can_sign_in_with_an_emailed_code_and_replace_it(): void
    {
        $this->actingAs($this->owner);
        $this->enroll();
        $this->signOut();

        $this->spaPost('/api/v1/auth/login', ['email' => $this->owner->email, 'password' => self::PASSWORD])->assertStatus(202);
        $this->spaPost('/api/v1/auth/login/two-factor/email')->assertStatus(202)->assertJsonPath('data.otp_required', true);
        $code = null;
        Notification::assertSentTo($this->owner, LoginCodeNotification::class, function (LoginCodeNotification $n) use (&$code) {
            $code = $n->code;

            return true;
        });
        $this->spaPost('/api/v1/auth/login/two-factor', ['email_code' => '000000'])->assertStatus(422);
        $this->spaPost('/api/v1/auth/login/two-factor', ['email_code' => $code])->assertOk()->assertJsonPath('data.two_factor.recovery_sign_in', true);
        $this->assertTrue($this->audited('user.two_factor_email_code_used'));
        $this->sent(TwoFactorChangedNotification::EMAIL_SIGN_IN);

        $new = $this->replaceStarted([]);
        $this->spaPost('/api/v1/auth/two-factor/confirm', ['code' => Totp::codeAt($new, Totp::currentStep())])->assertOk();
    }

    public function test_it_is_optional_by_default_even_for_owners(): void
    {
        config(['engage.auth.owner_two_factor_required' => false]);
        $this->actingAs($this->owner);
        $this->spaGet('/api/v1/tenant')->assertOk();
        $this->spaGet('/api/v1/auth/two-factor')->assertJsonPath('data.required', false);
        [$secret] = $this->enroll();
        $this->withHeaders(self::SPA)->deleteJson('/api/v1/auth/two-factor', ['code' => $this->freshCode($secret)])->assertOk()->assertJsonPath('data.enabled', false);
    }
}
