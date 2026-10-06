<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Identity\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class AccountEmailTest extends TestCase
{
    private const SPA = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];

    private const PASSWORD = 'Secret123456';

    /** @return array<string, string> the query parameters of the link inside the verification email */
    private function linkFromEmail(User $user): array
    {
        $query = [];
        Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $n) use (&$query) {
            $this->assertStringStartsWith(config('engage.frontend_url').'/verify-email?', $n->url);
            parse_str((string) parse_url($n->url, PHP_URL_QUERY), $query);

            return true;
        });

        return $query;
    }

    private function register(string $email = 'jane@example.com'): User
    {
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/register', [
            'name' => 'Jane Doe', 'email' => $email, 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'company_name' => 'Acme Trading',
        ])->assertCreated();

        return User::query()->where('email', $email)->firstOrFail();
    }

    public function test_register_then_verify_then_access(): void
    {
        Notification::fake();
        $user = $this->register();

        // Enforced by the backend: every workspace endpoint refuses an unverified account …
        foreach (['/api/v1/tenant', '/api/v1/contacts', '/api/v1/conversations', '/api/v1/billing', '/api/v1/campaigns'] as $url) {
            $this->withHeaders(self::SPA)->getJson($url)->assertStatus(403)->assertJsonPath('error.code', 'email_unverified');
        }
        $this->withHeaders(self::SPA)->postJson('/api/v1/contacts', ['phone' => '+971501112233'])->assertStatus(403);
        // … while the account itself stays reachable, so the app can show "check your email".
        $this->withHeaders(self::SPA)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.user.email_verified', false);

        $link = $this->linkFromEmail($user);

        // A tampered or expired link does nothing.
        $this->postJson('/api/v1/auth/email/verify', ['token' => str_repeat('a', 64)] + $link)->assertStatus(422);
        $this->postJson('/api/v1/auth/email/verify', ['expires' => (string) ((int) $link['expires'] + 60)] + $link)->assertStatus(422);
        $this->assertNull($user->fresh()?->email_verified_at);

        $this->postJson('/api/v1/auth/email/verify', $link)->assertOk()->assertJsonPath('data.status', 'verified');
        $this->postJson('/api/v1/auth/email/verify', $link)->assertOk()->assertJsonPath('data.status', 'already');

        $this->app['auth']->forgetGuards(); // a new request loads the user afresh
        $this->withHeaders(self::SPA)->getJson('/api/v1/me')->assertJsonPath('data.user.email_verified', true);
        $this->withHeaders(self::SPA)->getJson('/api/v1/tenant')->assertOk()->assertJsonPath('data.name', 'Acme Trading');
    }

    public function test_the_verification_email_can_be_resent_but_not_spammed_and_expires(): void
    {
        Notification::fake();
        $user = $this->register();

        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/email/resend')->assertOk()->assertJsonPath('data.status', 'sent');
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/email/resend')->assertStatus(422); // one per minute
        Notification::assertSentToTimes($user, VerifyEmailNotification::class, 2); // registration + one resend

        $link = $this->linkFromEmail($user);
        $this->travel(49)->hours();
        $this->postJson('/api/v1/auth/email/verify', $link)->assertStatus(422);
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
        $this->postJson('/api/v1/auth/reset-password', ['email' => $query['email'], 'token' => $query['token'], 'password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422);
        $this->postJson('/api/v1/auth/reset-password', ['email' => $query['email'], 'token' => $query['token'], 'password' => $new, 'password_confirmation' => $new])
            ->assertOk()->assertJsonPath('data.status', 'reset');
        $this->postJson('/api/v1/auth/reset-password', ['email' => $query['email'], 'token' => $query['token'], 'password' => $new, 'password_confirmation' => $new])->assertStatus(422); // single use

        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login', ['email' => 'jane@example.com', 'password' => self::PASSWORD])->assertStatus(422);
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login', ['email' => 'jane@example.com', 'password' => $new])->assertOk()
            ->assertJsonPath('data.user.email_verified', true); // the reset link proved the address
    }
}
