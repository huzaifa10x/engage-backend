<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Identity\Models\User;
use App\Notifications\LoginCodeNotification;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class AccountEmailTest extends TestCase
{
    private const SPA = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];

    private const PASSWORD = 'Secret123456';

    /** The code inside the most recent verification email. */
    private function codeFromEmail(User $user): string
    {
        $code = '';
        Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $n) use (&$code) {
            $code = $n->code;

            return true;
        });
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        return $code;
    }

    private function wrong(string $code): string
    {
        return $code === '111111' ? '222222' : '111111';
    }

    private function register(string $email = 'jane@example.com'): User
    {
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/register', [
            'name' => 'Jane Doe', 'email' => $email, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'company_name' => 'Acme Trading',
        ])->assertCreated();

        return User::query()->where('email', $email)->firstOrFail();
    }

    private function verify(string $code): TestResponse
    {
        return $this->withHeaders(self::SPA)->postJson('/api/v1/auth/email/verify', ['code' => $code]);
    }

    public function test_register_then_enter_the_emailed_code_then_access(): void
    {
        Notification::fake();
        $user = $this->register();

        // Enforced by the backend: every workspace endpoint refuses an unverified account …
        foreach (['/api/v1/tenant', '/api/v1/contacts', '/api/v1/conversations', '/api/v1/billing', '/api/v1/campaigns'] as $url) {
            $this->withHeaders(self::SPA)->getJson($url)->assertStatus(403)->assertJsonPath('error.code', 'email_unverified');
        }
        $this->withHeaders(self::SPA)->postJson('/api/v1/contacts', ['phone' => '+971501112233'])->assertStatus(403);
        $this->withHeaders(self::SPA)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.user.email_verified', false);

        $code = $this->codeFromEmail($user);
        $this->verify('12')->assertStatus(422);                       // not a code at all
        $this->verify($this->wrong($code))->assertStatus(422)->assertJsonPath('error.details.fields.code.0', 'That code is not correct. Check the latest email and try again.');
        $this->assertNull($user->fresh()?->email_verified_at);

        // The right code (spaces as people type them) verifies the account; the session is already signed in.
        $this->verify(substr($code, 0, 3).' '.substr($code, 3))->assertOk()->assertJsonPath('data.status', 'verified');
        $this->app['auth']->forgetGuards(); // a new request loads the user afresh
        $this->withHeaders(self::SPA)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.user.email_verified', true);
        $this->withHeaders(self::SPA)->getJson('/api/v1/tenant')->assertOk()->assertJsonPath('data.name', 'Acme Trading');

        $this->verify($code)->assertOk()->assertJsonPath('data.status', 'already'); // nothing left to verify
    }

    public function test_a_code_expires_is_single_use_and_locks_after_five_wrong_guesses(): void
    {
        Notification::fake();
        $user = $this->register();
        $code = $this->codeFromEmail($user);

        // Five wrong guesses kill the code: even the right one is refused afterwards.
        for ($i = 1; $i <= 4; $i++) {
            $this->verify($this->wrong($code))->assertStatus(422);
        }
        $this->verify($this->wrong($code))->assertStatus(422)->assertJsonPath('error.details.fields.code.0', 'Too many wrong codes. Request a new code.');
        $this->verify($code)->assertStatus(422)->assertJsonPath('error.details.fields.code.0', 'Too many wrong codes. Request a new code.');
        $this->assertNull($user->fresh()?->email_verified_at);

        // A new code replaces the old one …
        $this->travel(61)->seconds();
        Notification::fake();
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/email/resend')->assertOk()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.expires_in', 600);
        $fresh = $this->codeFromEmail($user);

        // … and expires after 10 minutes.
        $this->travel(11)->minutes();
        RateLimiter::clear('otp-verify:'.$user->id);
        $this->verify($fresh)->assertStatus(422)->assertJsonPath('error.details.fields.code.0', 'This code has expired. Request a new one.');
        $this->assertNull($user->fresh()?->email_verified_at);
    }

    public function test_resending_and_guessing_are_rate_limited(): void
    {
        Notification::fake();
        $user = $this->register(); // 1st code

        // At most one code a minute …
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/email/resend')->assertStatus(422);
        // … and five an hour.
        for ($i = 2; $i <= 5; $i++) {
            $this->travel(61)->seconds();
            $this->withHeaders(self::SPA)->postJson('/api/v1/auth/email/resend')->assertOk();
        }
        $this->travel(61)->seconds();
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/email/resend')->assertStatus(422);
        Notification::assertSentToTimes($user, VerifyEmailNotification::class, 5);

        // Guessing is capped per account as well, whatever happens to individual codes.
        for ($i = 1; $i <= 10; $i++) {
            $this->verify('000000')->assertStatus(422);
        }
        $this->verify('000000')->assertStatus(422)->assertJsonPath('error.details.fields.code.0', fn (string $m) => str_starts_with($m, 'Too many attempts'));

        // Verification needs a signed-in session: an anonymous caller cannot verify anything.
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/logout');
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/email/verify', ['code' => '123456'])->assertStatus(401);
    }

    public function test_signing_in_unverified_sends_a_new_code(): void
    {
        Notification::fake();
        $user = $this->register();
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/logout');
        $this->app['auth']->forgetGuards();
        $this->travel(2)->minutes();
        Notification::fake();

        // Signing in asks for an emailed code; entering it also proves the address.
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login', ['email' => 'jane@example.com', 'password' => self::PASSWORD])
            ->assertStatus(202)->assertJsonPath('data.otp_required', true);
        $code = '';
        Notification::assertSentTo($user, LoginCodeNotification::class, function (LoginCodeNotification $n) use (&$code) {
            $code = $n->code;

            return true;
        });
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login/verify', ['code' => $code])->assertOk()->assertJsonPath('data.user.email_verified', true);
        $this->withHeaders(self::SPA)->getJson('/api/v1/tenant')->assertOk();
    }

    public function test_password_reset_by_email(): void
    {
        Notification::fake();
        $user = $this->register();
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/logout');
        $this->app['auth']->forgetGuards();

        // Same answer whether or not the address has an account.
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk()->assertJsonPath('data.status', 'sent');
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'Jane@Example.com'])->assertOk()->assertJsonPath('data.status', 'sent');

        $query = [];
        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $n) use (&$query) {
            $this->assertStringStartsWith(config('engage.frontend_url').'/reset-password?', $n->url);
            parse_str((string) parse_url($n->url, PHP_URL_QUERY), $query);

            return true;
        });

        $new = 'Brand-New-Passw0rd';
        $this->postJson('/api/v1/auth/reset-password', ['email' => $query['email'], 'token' => 'wrong-token', 'password' => $new, 'password_confirmation' => $new])->assertStatus(422);
        $this->postJson('/api/v1/auth/reset-password', ['email' => $query['email'], 'token' => $query['token'], 'password' => $new, 'password_confirmation' => $new])
            ->assertOk()->assertJsonPath('data.status', 'reset');
        $this->postJson('/api/v1/auth/reset-password', ['email' => $query['email'], 'token' => $query['token'], 'password' => $new, 'password_confirmation' => $new])->assertStatus(422); // single use

        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login', ['email' => 'jane@example.com', 'password' => self::PASSWORD])->assertStatus(422);
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login', ['email' => 'jane@example.com', 'password' => $new])->assertStatus(202)
            ->assertJsonPath('data.otp_required', true);
        $this->assertNotNull($user->fresh()?->email_verified_at); // the reset link proved the address

        // A reset link is only good for 5 minutes.
        $this->travel(2)->minutes();
        Notification::fake();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'jane@example.com'])->assertOk();
        $late = [];
        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $n) use (&$late) {
            $this->assertSame(5, $n->minutes);
            parse_str((string) parse_url($n->url, PHP_URL_QUERY), $late);

            return true;
        });
        $this->travel(6)->minutes();
        $this->postJson('/api/v1/auth/reset-password', ['email' => $late['email'], 'token' => $late['token'], 'password' => 'Another-Passw0rd-1', 'password_confirmation' => 'Another-Passw0rd-1'])->assertStatus(422);
    }
}
