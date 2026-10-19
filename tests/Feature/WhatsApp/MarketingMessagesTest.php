<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Enums\MarketingMessagesStatus;
use App\Domain\WhatsApp\Models\MmOnboardingEvent;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * Marketing Messages API: status mirrored from Meta, enrollment at onboarding and for existing
 * accounts, the MM_LITE_TERMS_SIGNED webhook, and campaign marketing routed through
 * /marketing_messages only for onboarded accounts (Cloud API otherwise, and as the fallback).
 */
final class MarketingMessagesTest extends TestCase
{
    use InteractsWithWhatsapp;

    private const WABA = '102290129340398';

    private const PHONE = '106540352242922';

    private const BUSINESS = '2729063490586005';

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    /** What the fake Meta answers for the WABA's Marketing Messages fields. */
    private array $mmNode = ['marketing_messages_onboarding_status' => 'ELIGIBLE', 'owner_business_info' => ['id' => self::BUSINESS, 'marketing_messages_onboarding_status' => ['status' => 'NOT_STARTED']]];

    /** null = the Intent API accepts; otherwise Meta's error message. */
    private ?string $intentError = null;

    /** A Meta error code for every /marketing_messages send (null = accepted). */
    private ?int $mmSendError = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant(['timezone' => 'Asia/Dubai']);
        $this->tenantContext()->bypass(fn () => $this->tenant->forceFill(['settings' => ['compliance' => ['quiet_hours_enabled' => false]]])->save());
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
        $this->tenantContext()->bypass(fn () => WabaAccount::query()->where('waba_id', self::WABA)->update(['meta_business_id' => self::BUSINESS]));
        $this->actingAsMember($this->owner);

        $v = 'graph.facebook.com/v26.0';
        $send = fn () => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '0']], 'messages' => [['id' => 'wamid.S'.bin2hex(random_bytes(6))]]]);
        Http::fake([
            "{$v}/".self::WABA.'/message_templates*' => Http::response(['data' => [
                ['id' => '900001', 'name' => 'summer_offer', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'MARKETING',
                    'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, enjoy 20% off this week.'], ['type' => 'FOOTER', 'text' => 'Reply STOP to opt out']]],
                ['id' => '900002', 'name' => 'order_update', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY',
                    'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, your order has shipped.']]],
            ]]),
            "{$v}/".self::PHONE.'/marketing_messages*' => fn () => $this->mmSendError === null ? $send()
                : Http::response(['error' => ['message' => 'Marketing Messages API is not available', 'type' => 'OAuthException', 'code' => $this->mmSendError]], 400),
            "{$v}/".self::PHONE.'/messages*' => $send,
            "{$v}/".self::BUSINESS.'/onboard_partners_to_mm_lite*' => fn () => $this->intentError === null
                ? Http::response(['request_id' => 'req-123'])
                : Http::response(['error' => ['message' => $this->intentError, 'type' => 'OAuthException', 'code' => 200]], 400),
            "{$v}/".self::WABA.'*' => fn () => Http::response(['id' => self::WABA] + $this->mmNode),
        ]);

        $this->postJson('/api/v1/templates/sync')->assertOk();
        foreach (['+971501110001', '+971501110002'] as $phone) {
            $this->postJson('/api/v1/contacts', ['phone' => $phone, 'opted_in' => true])->assertCreated();
        }
    }

    private function waba(): WabaAccount
    {
        return $this->tenantContext()->bypass(fn () => WabaAccount::query()->where('waba_id', self::WABA)->firstOrFail());
    }

    private function onboard(): void
    {
        $this->tenantContext()->bypass(fn () => WabaAccount::query()->where('waba_id', self::WABA)->update(['mm_status' => 'onboarded']));
    }

    private function campaign(string $template = 'summer_offer'): string
    {
        $templateId = collect($this->getJson('/api/v1/templates')->json('data'))->firstWhere('name', $template)['id'];

        return (string) $this->postJson('/api/v1/campaigns', [
            'name' => 'Test', 'phone_number_id' => $this->number->id, 'template_id' => $templateId, 'variables' => ['body' => ['{{first_name|there}}']],
        ])->assertCreated()->json('data.id');
    }

    private function calls(string $endpoint): int
    {
        return Http::recorded(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), $endpoint) && $r->method() === 'POST')->count();
    }

    public function test_embedded_signup_offers_the_marketing_messages_terms(): void
    {
        $this->postJson('/api/v1/whatsapp/signups')->assertCreated()
            ->assertJsonPath('data.launch.login_options.extras.features.0.name', 'marketing_messages_lite');
    }

    public function test_an_eligible_existing_account_is_checked_and_asked_to_accept_the_terms(): void
    {
        Artisan::call('engage:mm:enroll');

        $waba = $this->waba();
        $this->assertSame(MarketingMessagesStatus::RequestSent, $waba->mm_status);
        $this->assertSame('ELIGIBLE', $waba->getAttribute('mm_meta_status'));
        $this->assertSame('backfill_batch', $waba->getAttribute('mm_consent_source'));
        $this->assertNotNull($waba->mm_requested_at);
        $this->assertSame(1, $this->calls('/onboard_partners_to_mm_lite'));

        // eligible (sync), then request_sent (the request) — both recorded.
        $events = $this->tenantContext()->bypass(fn () => MmOnboardingEvent::query()->orderBy('created_at')->pluck('to_status')->all());
        $this->assertSame(['eligible', 'request_sent'], $events);

        // Asking again within the week does not email the business twice.
        Artisan::call('engage:mm:enroll');
        $this->assertSame(1, $this->calls('/onboard_partners_to_mm_lite'));

        $this->getJson('/api/v1/whatsapp/accounts')->assertJsonPath('data.0.marketing_messages.status', 'request_sent')
            ->assertJsonPath('data.0.marketing_messages.route', 'cloud_api');
    }

    public function test_when_meta_refuses_the_request_the_business_accepts_in_whatsapp_manager(): void
    {
        $this->intentError = 'The app does not have sufficient permissions to call this API.';

        $this->postJson("/api/v1/whatsapp/accounts/{$this->waba()->id}/marketing-messages")->assertOk()
            ->assertJsonPath('meta.result', 'awaiting_client')
            ->assertJsonPath('data.marketing_messages.status', 'eligible')
            ->assertJsonPath('data.marketing_messages.request_error', 'The app does not have sufficient permissions to call this API.');
    }

    public function test_an_account_that_already_accepted_is_recorded_as_onboarded(): void
    {
        $this->mmNode = ['marketing_messages_onboarding_status' => 'ONBOARDED', 'owner_business_info' => ['id' => self::BUSINESS, 'marketing_messages_onboarding_status' => ['status' => 'TERM_OF_SERVICE_SIGNED']]];

        Artisan::call('engage:mm:enroll');

        $this->assertSame(MarketingMessagesStatus::Onboarded, $this->waba()->mm_status);
        $this->assertSame(0, $this->calls('/onboard_partners_to_mm_lite'));
    }

    public function test_an_ineligible_account_stays_on_the_cloud_api(): void
    {
        $this->mmNode = ['marketing_messages_onboarding_status' => 'INELIGIBLE'];

        Artisan::call('engage:mm:enroll');

        $this->assertSame(MarketingMessagesStatus::NotEligible, $this->waba()->mm_status);
        $this->assertSame(0, $this->calls('/onboard_partners_to_mm_lite'));
    }

    public function test_the_terms_signed_webhook_onboards_the_account_even_on_the_business_entry(): void
    {
        // Meta can send it on the business portfolio's entry: the WABA is named in waba_info.
        $this->postWebhook($this->webhookBody('account_update', [
            'event' => 'MM_LITE_TERMS_SIGNED',
            'waba_info' => ['waba_id' => self::WABA, 'owner_business_id' => self::BUSINESS],
        ], self::BUSINESS))->assertOk();

        $waba = $this->waba();
        $this->assertSame(MarketingMessagesStatus::Onboarded, $waba->mm_status);
        $this->assertNotNull($waba->mm_onboarded_at);
        $this->assertSame('webhook', $this->tenantContext()->bypass(fn () => MmOnboardingEvent::query()->latest('created_at')->value('trigger')));

        // Repeated delivery of the same event changes nothing.
        $this->postWebhook($this->webhookBody('account_update', ['event' => 'MM_LITE_TERMS_SIGNED', 'waba_info' => ['waba_id' => self::WABA, 'owner_business_id' => self::BUSINESS], 'x' => 1], self::BUSINESS))->assertOk();
        $this->assertSame(1, $this->tenantContext()->bypass(fn () => MmOnboardingEvent::query()->where('to_status', 'onboarded')->count()));
    }

    public function test_a_business_level_terms_webhook_onboards_every_account_of_that_business(): void
    {
        $this->postWebhook($this->webhookBody('account_update', [
            'event' => 'MM_LITE_TERMS_SIGNED', 'waba_info' => ['owner_business_id' => self::BUSINESS],
        ], self::BUSINESS))->assertOk();

        $this->assertSame(MarketingMessagesStatus::Onboarded, $this->waba()->mm_status);
    }

    public function test_an_onboarded_account_sends_campaign_marketing_through_the_marketing_messages_api(): void
    {
        $this->onboard();
        $id = $this->campaign();
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.send_route', 'mm_api');

        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertOk();

        $this->assertSame(2, $this->calls('/marketing_messages'));
        $this->assertSame(0, $this->calls('/'.self::PHONE.'/messages'));
        $request = Http::recorded(fn (Request $r) => str_contains($r->url(), '/marketing_messages'))->first()[0];
        $this->assertSame('CLOUD_API_FALLBACK', $request['product_policy']);
        $this->assertTrue($request['message_activity_sharing']);
        $this->assertSame('template', $request['type']);

        $wamid = $this->tenantContext()->bypass(fn () => Message::query()->where('campaign_id', $id)->value('wamid'));
        $this->assertSame(2, $this->tenantContext()->bypass(fn () => Message::query()->where('campaign_id', $id)->where('send_route', 'mm_api')->count()));

        // Meta prices MM API deliveries as marketing_lite; plain "marketing" means it fell back to the Cloud API.
        $this->postWebhook($this->webhookBody('messages', [
            'messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '971585496310', 'phone_number_id' => self::PHONE],
            'statuses' => [['id' => $wamid, 'status' => 'delivered', 'timestamp' => (string) now()->timestamp, 'recipient_id' => '971501110001',
                'pricing' => ['billable' => true, 'pricing_model' => 'PMP', 'category' => 'marketing']]],
        ]))->assertOk();
        $this->assertSame('mm_fallback', $this->tenantContext()->bypass(fn () => Message::query()->where('wamid', $wamid)->value('send_route')));

        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.stats.routes.mm_api', 1)->assertJsonPath('data.stats.routes.cloud_api', 1);
    }

    public function test_an_account_not_onboarded_sends_campaign_marketing_through_the_cloud_api(): void
    {
        $id = $this->campaign();
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.send_route', 'cloud_api');

        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertOk();

        $this->assertSame(0, $this->calls('/marketing_messages'));
        $this->assertSame(2, $this->calls('/'.self::PHONE.'/messages'));
        $this->assertSame(2, $this->tenantContext()->bypass(fn () => Message::query()->where('campaign_id', $id)->where('send_route', 'cloud_api')->count()));
    }

    public function test_utility_campaigns_always_use_the_cloud_api(): void
    {
        $this->onboard();
        $id = $this->campaign('order_update');

        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertOk();

        $this->assertSame(0, $this->calls('/marketing_messages'));
        $this->assertSame(2, $this->calls('/'.self::PHONE.'/messages'));
    }

    public function test_a_marketing_messages_api_error_falls_back_to_the_cloud_api_instead_of_failing(): void
    {
        $this->onboard();
        $this->mmSendError = 131063;
        $id = $this->campaign();

        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertOk();

        $this->assertSame(2, $this->calls('/'.self::PHONE.'/messages'));
        $this->getJson("/api/v1/campaigns/{$id}")->assertJsonPath('data.stats.sent', 2)->assertJsonPath('data.stats.failed', 0)
            ->assertJsonPath('data.stats.routes.cloud_api', 2);
    }

    public function test_switching_the_feature_off_sends_everything_through_the_cloud_api(): void
    {
        config(['engage.meta.marketing_messages.enabled' => false]);
        $this->onboard();
        $id = $this->campaign();

        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertOk();

        $this->assertSame(0, $this->calls('/marketing_messages'));
        $this->postJson('/api/v1/whatsapp/signups')->assertCreated()->assertJsonMissingPath('data.launch.login_options.extras.features');
    }
}
