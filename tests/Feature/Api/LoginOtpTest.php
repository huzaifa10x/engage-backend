<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Application\Identity\LoginVerification;
use App\Domain\Identity\Models\User;
use App\Notifications\LoginCodeNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class LoginOtpTest extends TestCase
{
    private const SPA = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];

    private const PASSWORD = 'Secret-Passw0rd';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials(); // like a browser: cookies travel with API requests
        Notification::fake();
        $tenant = $this->createTenant();
        $this->user = $this->addMember($tenant)->user()->firstOrFail();
        $this->user->forceFill(['password' => self::PASSWORD, 'last_active_tenant_id' => $tenant->id])->save();
    }

    private function password(string $password = self::PASSWORD): TestResponse
    {
        return $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => $password]);
    }

    private function code(): string
    {
        $code = '';
        Notification::assertSentTo($this->user, LoginCodeNotification::class, function (LoginCodeNotification $n) use (&$code) {
            $code = $n->code;

            return true;
        });

        return $code;
    }

    /** Signs out and forgets everything this "browser" held except its trusted-device cookie. */
    private function signOut(?string $trustCookie): void
    {
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/logout');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        if ($trustCookie !== null) {
            $this->withCookie(LoginVerification::COOKIE, $trustCookie);
        }
    }

    public function test_the_password_alone_does_not_sign_in_and_the_emailed_code_completes_it(): void
    {
        $this->password('wrong-password')->assertStatus(422);
        Notification::assertNothingSent();

        $this->password()->assertStatus(202)->assertJsonPath('data.otp_required', true)->assertJsonPath('data.expires_in', 600)
            ->assertJsonMissingPath('data.user');                       // nothing about the account yet
        $this->withHeaders(self::SPA)->getJson('/api/v1/me')->assertStatus(401); // not signed in
        $this->withHeaders(self::SPA)->getJson('/api/v1/tenant')->assertStatus(401);

        $code = $this->code();
        $wrong = $code === '111111' ? '222222' : '111111';
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login/verify', ['code' => $wrong])->assertStatus(422);
        $this->withHeaders(self::SPA)->getJson('/api/v1/me')->assertStatus(401);

        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login/verify', ['code' => $code])->assertOk()
            ->assertJsonPath('data.user.email', $this->user->email)->assertCookie(LoginVerification::COOKIE);
        $this->withHeaders(self::SPA)->getJson('/api/v1/tenant')->assertOk();

        // A code works once.
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login/verify', ['code' => $code])->assertStatus(422);
    }

    public function test_a_trusted_browser_skips_the_code_for_24_hours_and_another_browser_never_does(): void
    {
        $this->password()->assertStatus(202);
        $trust = (string) $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login/verify', ['code' => $this->code()])->assertOk()->getCookie(LoginVerification::COOKIE)?->getValue();

        // Same browser, 10 hours later: password only, no new email.
        $this->signOut($trust);
        $this->travel(10)->hours();
        Notification::fake();
        $this->password()->assertOk()->assertJsonPath('data.user.email', $this->user->email);
        Notification::assertNothingSent();

        // A different browser (no trust cookie) inside the same 24 hours is still asked for a code.
        $this->signOut(null);
        $this->password()->assertStatus(202)->assertJsonPath('data.otp_required', true);
        Notification::assertSentTo($this->user, LoginCodeNotification::class);

        // The first browser again, after the 24 hours are over: a code is required once more.
        $this->signOut($trust);
        $this->travel(15)->hours();
        Notification::fake();
        $this->password()->assertStatus(202)->assertJsonPath('data.otp_required', true);
        Notification::assertSentTo($this->user, LoginCodeNotification::class);
    }

    public function test_an_open_session_is_signed_out_when_its_24_hours_are_over(): void
    {
        $this->password()->assertStatus(202);
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login/verify', ['code' => $this->code()])->assertOk();

        $this->travel(23)->hours();
        $this->withHeaders(self::SPA)->getJson('/api/v1/tenant')->assertOk();

        $this->travel(2)->hours();
        $this->withHeaders(self::SPA)->getJson('/api/v1/tenant')->assertStatus(401)->assertJsonPath('error.code', 'session_expired');
        $this->app['auth']->forgetGuards();
        $this->withHeaders(self::SPA)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_codes_expire_lock_after_wrong_guesses_and_resends_are_limited(): void
    {
        $this->password()->assertStatus(202);
        $code = $this->code();
        $wrong = $code === '111111' ? '222222' : '111111';

        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login/resend')->assertStatus(422); // one a minute

        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login/verify', ['code' => $wrong])->assertStatus(422);
        }
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login/verify', ['code' => $code])->assertStatus(422)
            ->assertJsonPath('error.details.fields.code.0', 'Too many wrong codes. Request a new code.');

        $this->travel(61)->seconds();
        Notification::fake();
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login/resend')->assertStatus(202);
        $fresh = $this->code();

        $this->travel(11)->minutes();
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login/verify', ['code' => $fresh])->assertStatus(422)
            ->assertJsonPath('error.details.fields.code.0', 'This code has expired. Request a new one.');

        // Without a pending sign-in there is nothing to verify or resend.
        $this->signOut(null);
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login/verify', ['code' => '123456'])->assertStatus(422);
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login/resend')->assertStatus(422);
    }

    public function test_the_code_step_can_be_switched_off_for_emergencies(): void
    {
        config(['engage.login_otp.enabled' => false]);

        $this->password()->assertOk()->assertJsonPath('data.user.email', $this->user->email);
        Notification::assertNothingSent();
        $this->travel(3)->days();
        $this->withHeaders(self::SPA)->getJson('/api/v1/tenant')->assertOk();
    }
}
