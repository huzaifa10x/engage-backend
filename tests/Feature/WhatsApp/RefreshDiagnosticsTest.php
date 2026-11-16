<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** "Could not refresh from Meta… missing permissions": every failed refresh is logged with what the token can do. */
final class RefreshDiagnosticsTest extends TestCase
{
    use InteractsWithWhatsapp;

    public function test_a_failed_refresh_logs_the_account_metas_codes_and_the_tokens_real_permissions_never_the_token(): void
    {
        $this->configureMeta();
        $tenant = $this->createTenant();
        $this->subscribe($tenant, 'pro');
        $owner = $this->addMember($tenant);
        $this->connectNumber($tenant);
        $waba = $this->tenantContext()->run($tenant, fn () => WabaAccount::query()->firstOrFail());

        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/*/debug_token*' => Http::response(['data' => [
                'app_id' => '1234', 'type' => 'SYSTEM_USER', 'is_valid' => true, 'user_id' => '999', 'expires_at' => 0,
                'scopes' => ['whatsapp_business_messaging', 'business_management'],
                'granular_scopes' => [
                    ['scope' => 'whatsapp_business_messaging', 'target_ids' => ['555000111']], // another account only
                    ['scope' => 'business_management'],
                ],
            ]]),
            'graph.facebook.com/*/102290129340398*' => Http::response(['error' => [
                'message' => '(#200) Requires whatsapp_business_management permission to manage the object', 'type' => 'OAuthException',
                'code' => 200, 'error_subcode' => 2494013, 'fbtrace_id' => 'AbCdEf123',
            ]], 403),
        ]);
        Log::spy();

        $this->actingAsMember($owner);
        $this->postJson("/api/v1/whatsapp/accounts/{$waba->id}/refresh")->assertStatus(403)->assertJsonPath('error.code', 'meta_permission_missing');

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context) use ($waba): bool {
            if ($message !== 'WhatsApp account refresh failed') {
                return false;
            }
            $this->assertSame('channels_refresh', $context['where']);
            $this->assertSame($waba->id, $context['waba_account_id']);
            $this->assertSame('102290129340398', $context['waba_id']);
            $this->assertSame(200, $context['meta_code']);
            $this->assertSame(2494013, $context['meta_subcode']);
            $this->assertSame('AbCdEf123', $context['fbtrace_id']);
            $this->assertSame('SYSTEM_USER', $context['debug_token']['type']);
            $this->assertSame(['whatsapp_business_messaging', 'business_management'], $context['debug_token']['scopes']);
            $this->assertFalse($context['debug_token']['whatsapp_scopes']['whatsapp_business_messaging']['covers_this_account']);
            $this->assertTrue($context['debug_token']['whatsapp_scopes']['business_management']['covers_this_account']);
            $this->assertArrayNotHasKey('whatsapp_business_management', $context['debug_token']['whatsapp_scopes'], 'the missing permission shows as missing');
            $this->assertStringNotContainsString('EAA-existing-token', json_encode($context));

            return true;
        })->once();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'debug_token') && $r['input_token'] === 'EAA-existing-token');
    }
}
