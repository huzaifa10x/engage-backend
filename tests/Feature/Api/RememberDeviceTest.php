<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Application\Identity\LoginVerification;
use App\Domain\Identity\Models\User;
use App\Notifications\LoginCodeNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** "Remember this device for 30 days" at sign-in. */
final class RememberDeviceTest extends TestCase
{
    private const SPA = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];

    private const PASSWORD = 'Secret-Passw0rd';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        Notification::fake();
        $tenant = $this->createTenant();
        $this->user = $this->addMember($tenant)->user()->firstOrFail();
        $this->user->forceFill(['password' => self::PASSWORD, 'last_active_tenant_id' => $tenant->id])->save();
    }

    /** @param array<string, mixed> $extra */
    private function password(array $extra = []): TestResponse
    {
        return $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => self::PASSWORD] + $extra);
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

    /**
     * Signs in with a code; returns the trusted-device cookie.
     *
     * @param  array<string, mixed>  $login
     * @param  array<string, mixed>  $verify
     */
    private function signInWithCode(array $login = [], array $verify = []): string
    {
        $this->travel(2)->minutes(); // past the one-code-a-minute limit
        Notification::fake();
        $this->password($login)->assertStatus(202);
        $cookie = $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login/verify', ['code' => $this->code()] + $verify)->assertOk()->getCookie(LoginVerification::COOKIE);
        $this->assertNotNull($cookie);

        return (string) $cookie->getValue();
    }

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

    private function asksForCode(): void
    {
        $this->travel(2)->minutes(); // past the one-code-a-minute limit
        Notification::fake();
        $this->password()->assertStatus(202)->assertJsonPath('data.otp_required', true);
        Notification::assertSentTo($this->user, LoginCodeNotification::class);
    }

    private function signsInDirectly(): void
    {
        Notification::fake();
        $this->password()->assertOk()->assertJsonPath('data.user.email', $this->user->email);
        Notification::assertNothingSent();
    }

    public function test_a_remembered_device_signs_in_without_a_code_for_30_days(): void
    {
        $this->password(['remember_device' => true])->assertStatus(202);
        $response = $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login/verify', ['code' => $this->code()])->assertOk();
        $cookie = $response->getCookie(LoginVerification::COOKIE);
        $this->assertNotNull($cookie);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $cookie->getExpiresTime(), 5);
        $trust = (string) $cookie->getValue();

        $this->withCookie(LoginVerification::COOKIE, $trust)->withHeaders(self::SPA)->getJson('/api/v1/auth/remembered-devices')->assertOk()
            ->assertJsonPath('data.this_device.remembered', true)->assertJsonPath('data.remember_days', 30);

        // Day 2, day 10, day 27: password only, no email, and the session stays open past 24 hours.
        foreach ([2, 8, 17] as $days) {
            $this->signOut($trust);
            $this->travel($days)->days();
            $this->signsInDirectly();
            $this->travel(25)->hours();
            $this->withHeaders(self::SPA)->getJson('/api/v1/tenant')->assertOk();
            $this->travel(-25)->hours();
        }

        // After 30 days: the code is asked again.
        $this->signOut($trust);
        $this->travel(4)->days();
        $this->asksForCode();
    }

    public function test_an_open_session_on_a_remembered_device_ends_after_30_days(): void
    {
        $this->signInWithCode(['remember_device' => true]);
        $this->travel(29)->days();
        $this->withHeaders(self::SPA)->getJson('/api/v1/tenant')->assertOk();
        $this->travel(2)->days();
        $this->withHeaders(self::SPA)->getJson('/api/v1/tenant')->assertStatus(401)->assertJsonPath('error.code', 'session_expired');
    }

    public function test_without_the_tick_the_24_hour_window_is_unchanged(): void
    {
        $trust = $this->signInWithCode();
        $this->withCookie(LoginVerification::COOKIE, $trust)->withHeaders(self::SPA)->getJson('/api/v1/auth/remembered-devices')->assertOk()->assertJsonPath('data.this_device.remembered', false);

        $this->signOut($trust);
        $this->travel(10)->hours();
        $this->signsInDirectly();

        $this->signOut($trust);
        $this->travel(15)->hours();
        $this->asksForCode();
    }

    public function test_the_tick_can_also_be_sent_with_the_code(): void
    {
        $trust = $this->signInWithCode([], ['remember_device' => true]);
        $this->signOut($trust);
        $this->travel(5)->days();
        $this->signsInDirectly();
    }

    public function test_another_browser_is_still_asked_for_a_code(): void
    {
        $this->signInWithCode(['remember_device' => true]);
        $this->signOut(null);
        $this->asksForCode();
    }

    public function test_forget_remembered_devices_asks_every_browser_for_a_code_and_keeps_this_one_signed_in(): void
    {
        // Browser A remembered, still signed in.
        $a = $this->signInWithCode(['remember_device' => true]);
        $sessionA = $this->app['session']->all();

        // Browser B remembered too; from B the person forgets all remembered devices.
        $this->signOut(null);
        $b = $this->signInWithCode(['remember_device' => true]);
        $this->travel(1)->minutes();
        $this->withCookie(LoginVerification::COOKIE, $b)->withHeaders(self::SPA)->deleteJson('/api/v1/auth/remembered-devices')->assertOk()
            ->assertJsonPath('data.this_device.remembered', false)->assertCookieExpired(LoginVerification::COOKIE);
        $this->withHeaders(self::SPA)->getJson('/api/v1/tenant')->assertOk(); // B stays signed in

        // A's open session is over, and neither browser skips the code any more.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->withSession($sessionA)->withHeaders(self::SPA)->getJson('/api/v1/tenant')->assertStatus(401)->assertJsonPath('error.code', 'session_expired');
        foreach ([$a, $b] as $trust) {
            $this->signOut($trust);
            $this->asksForCode();
        }
    }

    public function test_support_sign_out_everywhere_also_ends_remembered_devices(): void
    {
        $trust = $this->signInWithCode(['remember_device' => true]);
        $this->travel(1)->minutes();
        $this->user->forceFill(['sessions_revoked_at' => now()])->save();
        $this->signOut($trust);
        $this->travel(1)->minutes();
        $this->asksForCode();
    }

    public function test_a_password_reset_ends_remembered_devices(): void
    {
        $trust = $this->signInWithCode(['remember_device' => true]);
        $this->signOut($trust);
        $this->travel(1)->minutes();
        $token = Password::broker()->createToken($this->user);
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/reset-password', [
            'email' => $this->user->email, 'token' => $token, 'password' => self::PASSWORD.'X', 'password_confirmation' => self::PASSWORD.'X',
        ])->assertOk();
        $this->user->forceFill(['password' => self::PASSWORD])->save();
        $this->signOut($trust);
        $this->asksForCode();
    }

    public function test_a_browser_trusted_before_the_upgrade_keeps_its_24_hours(): void
    {
        // The old cache entry was the time alone.
        $token = str_repeat('a', 64);
        Cache::put('login-trust:'.$this->user->id.':'.hash('sha256', $token), now()->timestamp, now()->addDay());
        $this->withCookie(LoginVerification::COOKIE, $token);
        $this->signsInDirectly();

        $this->signOut($token);
        $this->travel(25)->hours();
        $this->asksForCode();
    }

    public function test_support_staff_signed_in_as_the_user_cannot_forget_devices(): void
    {
        $this->signInWithCode(['remember_device' => true]);
        Context::addHidden('impersonator_id', 'admin-1');
        $this->withHeaders(self::SPA)->deleteJson('/api/v1/auth/remembered-devices')->assertStatus(422);
    }
}
