<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\WhatsApp\Enums\OnboardingType;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Enums\WabaStatus;
use App\Domain\WhatsApp\Models\MetaAccessToken;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Infrastructure\Meta\GraphClient;
use App\Infrastructure\Secrets\SecretStore;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

trait InteractsWithWhatsapp
{
    protected const APP_SECRET = 'test-app-secret';

    protected function configureMeta(bool $coexistence = false): void
    {
        config([
            'engage.meta.app_id' => '1234567890',
            'engage.meta.app_secret' => self::APP_SECRET,
            'engage.meta.embedded_signup_config_id' => '555000',
            'engage.meta.webhook_verify_token' => 'verify-me',
            'engage.meta.coexistence_enabled' => $coexistence,
        ]);
        $this->app->forgetInstance(GraphClient::class);
    }

    /** A connected WABA + number, as if onboarding had completed. */
    protected function connectNumber(Tenant $tenant, string $wabaId = '102290129340398', string $phoneNumberId = '106540352242922', string $display = '+971 58 549 6310', OnboardingType $type = OnboardingType::NewNumber): PhoneNumber
    {
        return $this->tenantContext()->run($tenant, function () use ($wabaId, $phoneNumberId, $display, $type) {
            $token = MetaAccessToken::query()->create([
                'secret_id' => app(SecretStore::class)->put('meta.business_token', 'EAA-existing-token', $this->tenantContext()->id()),
                'meta_app_id' => '1234567890',
            ]);
            $waba = WabaAccount::query()->create([
                'waba_id' => $wabaId, 'access_token_id' => $token->id, 'status' => WabaStatus::Connected,
                'is_subscribed_to_webhooks' => true, 'connected_at' => now(),
            ]);

            return PhoneNumber::query()->create([
                'waba_account_id' => $waba->id, 'phone_number_id' => $phoneNumberId, 'display_phone_number' => $display,
                'status' => PhoneNumberStatus::Connected, 'onboarding_type' => $type, 'quality_rating' => 'GREEN',
                'messaging_limit_tier' => 'TIER_1K', 'capabilities' => PhoneNumber::capabilitiesFor($type),
            ]);
        });
    }

    /** Fakes the Graph endpoints of Embedded Signup onboarding (Tech Provider path). */
    protected function fakeGraph(string $wabaId = '102290129340398', string $phoneNumberId = '106540352242922', ?array $grantedWabas = null): void
    {
        $v = 'graph.facebook.com/v25.0';
        $granted = $grantedWabas ?? [$wabaId];

        Http::preventStrayRequests();
        Http::fake([
            "{$v}/oauth/access_token*" => Http::response(['access_token' => 'EAAG-business-token', 'token_type' => 'bearer']),
            "{$v}/debug_token*" => Http::response(['data' => [
                'app_id' => '1234567890', 'type' => 'SYSTEM_USER', 'is_valid' => true, 'user_id' => '9988776655', 'expires_at' => 0,
                'scopes' => ['whatsapp_business_management', 'whatsapp_business_messaging'],
                'granular_scopes' => [
                    ['scope' => 'whatsapp_business_management', 'target_ids' => $granted],
                    ['scope' => 'whatsapp_business_messaging', 'target_ids' => $granted],
                ],
            ]]),
            "{$v}/{$wabaId}/subscribed_apps*" => Http::response(['success' => true]),
            "{$v}/{$phoneNumberId}/register*" => Http::response(['success' => true]),
            "{$v}/{$phoneNumberId}/smb_app_data*" => Http::response(['messaging_product' => 'whatsapp', 'request_id' => 'req-'.uniqid()]),
            "{$v}/{$phoneNumberId}*" => Http::response([
                'id' => $phoneNumberId, 'display_phone_number' => '+971 58 549 6310', 'verified_name' => 'Nova Fitness',
                'name_status' => 'APPROVED', 'quality_rating' => 'GREEN', 'code_verification_status' => 'VERIFIED',
                'platform_type' => 'CLOUD_API', 'throughput' => ['level' => 'STANDARD'], 'messaging_limit_tier' => 'TIER_250',
                'is_official_business_account' => false,
            ]),
            "{$v}/{$wabaId}*" => Http::response([
                'id' => $wabaId, 'name' => 'Nova Fitness WABA', 'currency' => 'USD', 'timezone_id' => '71',
                'message_template_namespace' => 'abc_123', 'account_review_status' => 'APPROVED',
                'owner_business_info' => ['id' => '2729063490586005', 'name' => 'Nova Fitness LLC'],
            ]),
        ]);
    }

    /**
     * `messages` webhook value for one inbound customer message (text unless overridden).
     *
     * @param  array<string, mixed>  $overrides  merged over the message object
     * @return array<string, mixed>
     */
    protected function inboundValue(array $overrides = [], ?string $waId = '971501234567', ?string $bsuid = 'AE.12345678901234567890', string $name = 'Sara Ahmed', string $phoneNumberId = '106540352242922'): array
    {
        $message = array_merge(array_filter([
            'from' => $waId,
            'from_user_id' => $bsuid,
            'id' => 'wamid.IN'.bin2hex(random_bytes(6)),
            'timestamp' => (string) now()->timestamp,
            'type' => 'text',
            'text' => ['body' => 'Hi, do you have this in blue?'],
        ], fn ($v) => $v !== null), $overrides);

        return [
            'messaging_product' => 'whatsapp',
            'metadata' => ['display_phone_number' => '971585496310', 'phone_number_id' => $phoneNumberId],
            'contacts' => [array_filter(['profile' => ['name' => $name], 'wa_id' => $waId, 'user_id' => $bsuid], fn ($v) => $v !== null)],
            'messages' => [$message],
        ];
    }

    /** @param array<string, mixed> $value */
    protected function webhookBody(string $field, array $value, string $wabaId = '102290129340398'): string
    {
        return (string) json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => $wabaId, 'time' => 1767225600, 'changes' => [['field' => $field, 'value' => $value]]]],
        ]);
    }

    protected function postWebhook(string $body, ?string $secret = self::APP_SECRET): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($secret !== null) {
            $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', '/api/webhooks/meta', [], [], [], $server, $body);
    }
}
