<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Tests\TestCase;

/** SPA flow: requests from a stateful origin get a session (Sanctum). */
final class AuthTest extends TestCase
{
    private const SPA = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];

    public function test_register_creates_workspace_and_returns_bootstrap_payload(): void
    {
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'Jane@Example.com',
            'password' => 'Secret123456',
            'password_confirmation' => 'Secret123456',
            'company_name' => 'Acme Trading',
        ])
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'jane@example.com')
            ->assertJsonPath('data.permissions', ['*'])
            ->assertJsonPath('data.entitlements.plan.key', 'pro')
            ->assertJsonCount(1, 'data.memberships');

        $this->withHeaders(self::SPA)->getJson('/api/v1/tenant')
            ->assertOk()
            ->assertJsonPath('data.name', 'Acme Trading');
    }

    public function test_login_rejects_bad_credentials_with_validation_envelope(): void
    {
        $this->withHeaders(self::SPA)->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['fields' => ['email']], 'request_id']]);
    }

    public function test_guest_gets_401_envelope(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');
    }
}
