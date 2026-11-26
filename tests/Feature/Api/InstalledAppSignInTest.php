<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Application\Identity\LoginVerification;
use App\Domain\Identity\Models\User;
use App\Notifications\LoginCodeNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Mobile brief: the installed app stays signed in for 90 days after its code, on one phone at a time;
 * and signing in with an emailed code instead of a password.
 */
final class InstalledAppSignInTest extends TestCase
{
    private const SPA = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];

    private const PASSWORD = 'Secret-Passw0rd';

    private const PHONE_A = 'phone-a-0123456789abcdef';

    private const PHONE_B = 'phone-b-0123456789abcdef';

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

    /** @param array<string, string> $headers */
    private function as(array $headers = []): self
    {
        return $this->withHeaders(self::SPA + $headers);
    }

    private function code(?User $user = null): string
    {
        $code = '';
        Notification::assertSentTo($user ?? $this->user, LoginCodeNotification::class, function (LoginCodeNotification $n) use (&$code) {
            $code = $n->code;

            return true;
        });

        return $code;
    }

    private function fresh(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->defaultHeaders = [];
    }

    /** Signs in from the installed app on a phone; returns the session it got. */
    private function appSignIn(string $phone): array
    {
        $this->travel(2)->minutes(); // past the one-code-a-minute limit
        Notification::fake();
        $device = [LoginVerification::DEVICE_HEADER => $phone];
        $this->as($device)->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => self::PASSWORD])->assertStatus(202);
        $this->as($device)->postJson('/api/v1/auth/login/verify', ['code' => $this->code()])->assertOk();

        return $this->app['session']->all();
    }

    private function tenant(string $phone): TestResponse
    {
        return $this->as([LoginVerification::DEVICE_HEADER => $phone])->getJson('/api/v1/tenant');
    }

    public function test_the_installed_app_stays_signed_in_for_90_days(): void
    {
        $this->appSignIn(self::PHONE_A);
        $this->travel(89)->days();
        $this->tenant(self::PHONE_A)->assertOk();
        $this->travel(2)->days();
        $this->tenant(self::PHONE_A)->assertStatus(401)->assertJsonPath('error.code', 'session_expired');
    }

    public function test_signing_in_to_the_app_on_another_phone_signs_the_first_one_out(): void
    {
        $sessionA = $this->appSignIn(self::PHONE_A);
        $this->fresh();
        $this->appSignIn(self::PHONE_B);
        $this->tenant(self::PHONE_B)->assertOk();

        $this->fresh();
        $this->withSession($sessionA);
        $this->tenant(self::PHONE_A)->assertStatus(401)->assertJsonPath('error.code', 'app_signed_in_elsewhere');
    }

    public function test_a_browser_is_not_affected_and_does_not_borrow_the_apps_days(): void
    {
        // Signed in in a browser (24-hour window), then in the app on a phone.
        $this->travel(2)->minutes();
        $this->as()->postJson('/api/v1/auth/login', ['email' => $this->user->email, 'password' => self::PASSWORD])->assertStatus(202);
        $this->as()->postJson('/api/v1/auth/login/verify', ['code' => $this->code()])->assertOk();
        $browser = $this->app['session']->all();
        $this->fresh();
        $this->appSignIn(self::PHONE_A);

        $this->fresh();
        $this->withSession($browser);
        $this->as()->getJson('/api/v1/tenant')->assertOk();
        $this->travel(25)->hours();
        $this->as()->getJson('/api/v1/tenant')->assertStatus(401)->assertJsonPath('error.code', 'session_expired');
    }

    public function test_sign_in_with_an_emailed_code_instead_of_a_password(): void
    {
        $this->as()->postJson('/api/v1/auth/login/code', ['email' => strtoupper($this->user->email)])->assertStatus(202)->assertJsonPath('data.otp_required', true);
        $this->as()->postJson('/api/v1/auth/login/verify', ['code' => $this->code()])->assertOk()->assertJsonPath('data.user.email', $this->user->email);
        $this->as()->getJson('/api/v1/tenant')->assertOk();
    }

    public function test_an_unknown_address_gets_the_same_answer_and_no_email(): void
    {
        Notification::fake();
        $known = $this->as()->postJson('/api/v1/auth/login/code', ['email' => $this->user->email])->assertStatus(202)->json('data');
        $this->fresh();
        $unknown = $this->as()->postJson('/api/v1/auth/login/code', ['email' => 'nobody@example.com'])->assertStatus(202)->json('data');
        $this->assertSame(array_keys($known), array_keys($unknown));
        $this->as()->postJson('/api/v1/auth/login/verify', ['code' => '123456'])->assertStatus(422)
            ->assertJsonPath('error.details.fields.code.0', 'That code is not correct. Check the latest email and try again.');
        Notification::assertCount(1);
    }
}
