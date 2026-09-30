<?php

declare(strict_types=1);

namespace Tests\Feature\Meta;

use App\Domain\Privacy\Models\DataDeletionRequest;
use App\Domain\Privacy\SignedRequest;
use App\Domain\WhatsApp\Enums\SignupStatus;
use App\Domain\WhatsApp\Models\EmbeddedSignupAttempt;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

final class DataDeletionTest extends TestCase
{
    use InteractsWithWhatsapp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
    }

    private function signed(string $userId, string $secret = self::APP_SECRET): string
    {
        $payload = SignedRequest::base64UrlEncode((string) json_encode(['user_id' => $userId, 'algorithm' => 'HMAC-SHA256', 'issued_at' => time()]));

        return SignedRequest::base64UrlEncode(hash_hmac('sha256', $payload, $secret, true)).'.'.$payload;
    }

    public function test_valid_request_is_confirmed_and_personal_data_erased(): void
    {
        $tenant = $this->createTenant();
        $attempt = $this->tenantContext()->run($tenant, fn () => EmbeddedSignupAttempt::query()->create([
            'status' => SignupStatus::Completed, 'meta_user_id' => '10158888', 'session_payload' => ['waba_id' => '1'],
        ]));

        $response = $this->post('/api/meta/data-deletion', ['signed_request' => $this->signed('10158888')])
            ->assertOk()
            ->assertJsonStructure(['url', 'confirmation_code']);

        $code = $response->json('confirmation_code');
        $this->assertStringContainsString("/deletion-status?code={$code}", $response->json('url'));

        $request = DataDeletionRequest::query()->where('confirmation_code', $code)->first();
        $this->assertSame('completed', $request?->status);
        $this->assertSame(1, $request?->result['signup_attempts_anonymized'] ?? null);

        $fresh = $this->tenantContext()->bypass(fn () => EmbeddedSignupAttempt::query()->find($attempt->id));
        $this->assertNull($fresh?->getAttribute('meta_user_id'));

        $this->get("/deletion-status?code={$code}")->assertOk()->assertSeeText($code)->assertSeeText('Completed');
        $this->getJson("/api/meta/data-deletion/{$code}")->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_wrong_secret_is_rejected(): void
    {
        $this->post('/api/meta/data-deletion', ['signed_request' => $this->signed('1', 'wrong-secret')])->assertStatus(400);
        $this->post('/api/meta/data-deletion', ['signed_request' => 'garbage'])->assertStatus(400);

        $this->assertSame(0, DataDeletionRequest::query()->count());
    }
}
