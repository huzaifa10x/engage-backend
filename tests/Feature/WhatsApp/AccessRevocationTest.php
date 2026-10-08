<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Enums\OnboardingType;
use App\Domain\WhatsApp\Models\MetaAccessToken;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Notifications\ReconnectRequiredNotification;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * When a customer takes our access away at Meta, the workspace shows "disconnected", however we
 * find out: the account_update webhook, a refused API call, or the hourly check. And an error
 * that is NOT about access (an outage, one missing feature) never disconnects anything.
 */
final class AccessRevocationTest extends TestCase
{
    use InteractsWithWhatsapp;

    private const WABA = '102290129340398';

    private const PHONE = '106540352242922';

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        Notification::fake();
    }

    private function connect(OnboardingType $type = OnboardingType::NewNumber): void
    {
        $this->tenant = $this->createTenant(['name' => 'Palm Estates']);
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant, type: $type);
        $this->actingAsMember($this->owner);
    }

    /** @return array{0: string, 1: ?string, 2: string, 3: ?string} waba status, waba reason, number status, number reason */
    private function state(): array
    {
        $this->actingAsMember($this->owner);
        $waba = $this->getJson('/api/v1/whatsapp/accounts')->assertOk()->json('data.0');

        return [$waba['status'], $waba['disconnect_reason'], $waba['phone_numbers'][0]['status'], $waba['phone_numbers'][0]['disconnect_reason']];
    }

    private function tokenRevoked(): bool
    {
        return $this->tenantContext()->bypass(fn () => MetaAccessToken::query()->where('tenant_id', $this->tenant->id)->whereNull('revoked_at')->doesntExist());
    }

    /** Meta answers these URL patterns with these responses; everything else is a stray request and fails the test. */
    private function meta(array $responses): void
    {
        Http::preventStrayRequests();
        Http::fake($responses);
    }

    private function authError(int $code, ?int $subcode = null, int $status = 401): PromiseInterface
    {
        return Http::response(['error' => ['message' => 'Error validating access token: The session has been invalidated.', 'type' => 'OAuthException', 'code' => $code, 'error_subcode' => $subcode, 'fbtrace_id' => 'A1']], $status);
    }

    // ── 1. The webhook ─────────────────────────────────────────────────────────────────────

    public function test_partner_removed_from_meta_business_settings_disconnects_the_api_integration(): void
    {
        $this->connect();
        // Meta has sent the event name in both cases; lower case here.
        $this->postWebhook($this->webhookBody('account_update', ['event' => 'partner_removed']))->assertOk();

        $this->assertSame(['disconnected', 'partner_removed', 'disconnected', 'partner_removed'], $this->state());
        $this->assertTrue($this->tokenRevoked(), 'no usable credential is kept');
        Notification::assertSentOnDemand(ReconnectRequiredNotification::class, fn (ReconnectRequiredNotification $n) => str_contains($n->reason, 'removed as a partner'));
        // The dashboard is told: a bell notification that links to Channels.
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.items.0.type', 'channel_disconnected')->assertJsonPath('data.items.0.url', '/channels');
    }

    public function test_disconnecting_inside_the_whatsapp_business_app_marks_the_coexistence_number_disconnected(): void
    {
        $this->connect(OnboardingType::Coexistence);

        $this->postWebhook($this->webhookBody('account_update', ['event' => 'ACCOUNT_OFFBOARDED', 'phone_number' => '971585496310',
            'disconnection_info' => ['reason' => 'ACCOUNT_OFFBOARDED', 'initiated_by' => 'BUSINESS']]))->assertOk();

        [$wabaStatus, , $numberStatus, $numberReason] = $this->state();
        $this->assertSame('disconnected', $numberStatus);
        $this->assertSame('offboarded', $numberReason);
        $this->assertSame('disconnected', $wabaStatus, 'its only number is gone, so the account is too');
        $this->assertSame('offboarded', $this->getJson("/api/v1/phone-numbers/{$this->number->id}")->json('data.coexistence_status'));
        Notification::assertSentOnDemand(ReconnectRequiredNotification::class, fn (ReconnectRequiredNotification $n) => str_contains($n->reason, 'WhatsApp Business app'));

        // The same event again changes nothing and sends no second email.
        Notification::fake();
        $this->postWebhook($this->webhookBody('account_update', ['event' => 'ACCOUNT_OFFBOARDED', 'phone_number' => '971585496310', 'again' => true]))->assertOk();
        Notification::assertNothingSent();
    }

    public function test_the_other_removal_events_are_handled_too(): void
    {
        foreach (['PARTNER_APP_UNINSTALLED', 'ACCOUNT_DELETED'] as $event) {
            $this->connect();
            $this->postWebhook($this->webhookBody('account_update', ['event' => $event]))->assertOk();
            $this->assertSame('disconnected', $this->state()[0], $event);
            $this->tenantContext()->bypass(function (): void {
                PhoneNumber::query()->where('tenant_id', $this->tenant->id)->forceDelete();
                WabaAccount::query()->where('tenant_id', $this->tenant->id)->forceDelete();
            });
        }
    }

    // ── 2. A refused API call ──────────────────────────────────────────────────────────────

    public function test_a_dead_token_on_any_api_call_disconnects_the_account(): void
    {
        $this->connect();
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue()))->assertOk(); // window open
        $this->actingAsMember($this->owner);
        $conversation = $this->getJson('/api/v1/conversations')->json('data.0.id');

        // The customer removed 10X Engage in Meta Business Settings: Meta now refuses the token (error 190).
        $this->meta(['graph.facebook.com/*' => $this->authError(190, 460)]);
        $this->postJson("/api/v1/conversations/{$conversation}/messages", ['type' => 'text', 'body' => 'Hello'])->assertStatus(202);

        $this->assertSame(['disconnected', 'access_revoked', 'disconnected', 'access_revoked'], $this->state());
        $this->assertTrue($this->tokenRevoked());
        Notification::assertSentOnDemand(ReconnectRequiredNotification::class, fn (ReconnectRequiredNotification $n) => str_contains($n->reason, 'no longer accepts our access'));
        $this->getJson('/api/v1/notifications')->assertJsonPath('data.items.0.type', 'channel_disconnected');

        // History is kept, and sending is now refused up front instead of failing at Meta.
        $this->getJson("/api/v1/conversations/{$conversation}/messages")->assertOk()->assertJsonCount(2, 'data');
        $this->postJson("/api/v1/conversations/{$conversation}/messages", ['type' => 'text', 'body' => 'Again'])->assertStatus(409);
    }

    public function test_a_permission_error_is_double_checked_before_anything_is_disconnected(): void
    {
        // (a) One call is refused (error 200) but the account itself still answers: only that feature is missing.
        $this->connect();
        $this->meta([
            'graph.facebook.com/*/'.self::WABA.'/message_templates*' => $this->authError(200, null, 403),
            'graph.facebook.com/*/'.self::WABA.'?*' => Http::response(['id' => self::WABA, 'name' => 'Palm Estates']),
            'graph.facebook.com/*/'.self::WABA => Http::response(['id' => self::WABA, 'name' => 'Palm Estates']),
        ]);
        $this->postJson('/api/v1/templates/sync');
        $this->assertSame('connected', $this->state()[0], 'a narrow permission error must not disconnect the account');
        Notification::assertNothingSent();
    }

    public function test_a_permission_error_confirmed_on_the_account_itself_disconnects_it(): void
    {
        // (b) The account refuses even the simplest question: access is really gone.
        $this->connect();
        $this->meta(['graph.facebook.com/*' => $this->authError(200, null, 403)]);
        $this->postJson('/api/v1/templates/sync');

        $this->assertSame(['disconnected', 'access_revoked'], array_slice($this->state(), 0, 2));
    }

    public function test_an_outage_or_an_ordinary_error_never_disconnects(): void
    {
        $this->connect();
        foreach ([Http::response(['error' => ['message' => 'Service temporarily unavailable', 'code' => 2]], 503),
            Http::response(['error' => ['message' => 'Invalid parameter', 'code' => 100]], 400),
            Http::response(['error' => ['message' => 'Rate limit hit', 'code' => 80007]], 429)] as $response) {
            $this->meta(['graph.facebook.com/*' => $response]);
            $this->postJson('/api/v1/templates/sync');
            $this->artisan('engage:whatsapp:verify-access');
            $this->assertSame('connected', $this->state()[0]);
        }
        Notification::assertNothingSent();
    }

    // ── 3. The hourly check ────────────────────────────────────────────────────────────────

    public function test_the_hourly_check_finds_access_removed_while_nothing_was_being_sent(): void
    {
        $this->connect();
        $healthy = $this->createTenant();
        $this->subscribe($healthy, 'pro');
        $this->connectNumber($healthy, '202290129340398', '206540352242922', '+971 58 000 0002');

        // Meta refuses our token for the first account and still accepts it for the second.
        $this->meta([
            'graph.facebook.com/*/'.self::WABA.'*' => $this->authError(190),
            'graph.facebook.com/*/202290129340398*' => Http::response(['id' => '202290129340398', 'name' => 'Other']),
        ]);
        $this->artisan('engage:whatsapp:verify-access')->assertSuccessful();

        $this->assertSame(['disconnected', 'access_revoked'], array_slice($this->state(), 0, 2));
        $this->assertSame('connected', $this->tenantContext()->bypass(fn () => WabaAccount::query()->where('tenant_id', $healthy->id)->value('status'))->value);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '202290129340398'));

        // Already disconnected accounts are not asked about again.
        Http::fake(['graph.facebook.com/*/'.self::WABA.'*' => fn () => $this->fail('a disconnected account must not be checked')]);
    }
}
