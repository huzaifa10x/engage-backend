<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Enums\OnboardingType;
use App\Domain\WhatsApp\Models\MetaAccessToken;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Notifications\InternalAlertNotification;
use App\Notifications\ReconnectRequiredNotification;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
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

    public function test_a_lasting_permission_refusal_on_the_account_only_raises_attention(): void
    {
        // (b) The account refuses even the simplest question, again and again: suspicious, but only an
        // invalid token (190) or Meta's webhook disconnects. The team is alerted; the client sees nothing.
        config(['engage.alerts.email' => 'ops@10xdigital.ae']);
        $this->connect();
        $this->meta(['graph.facebook.com/*' => $this->authError(200, null, 403)]);
        $this->checks(3);

        $this->assertSame(['connected', null, 'connected', null], $this->state());
        $this->assertFalse($this->tokenRevoked());
        $waba = $this->tenantContext()->bypass(fn () => WabaAccount::query()->where('tenant_id', $this->tenant->id)->sole());
        $this->assertSame('account_refused', $waba->getAttribute('attention_reason'));
        $this->assertSame(200, $waba->getAttribute('attention_detail')['meta_response']['code']);
        Notification::assertSentOnDemand(InternalAlertNotification::class);
        Notification::assertNotSentTo(new AnonymousNotifiable, ReconnectRequiredNotification::class);
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

    // ── 4. Number checks only raise attention ──────────────────────────────────────────────

    public function test_a_number_reported_off_the_business_app_is_flagged_after_three_checks_not_disconnected(): void
    {
        $this->connect(OnboardingType::Coexistence);
        $this->tenantContext()->run($this->tenant, fn () => $this->number->forceFill(['is_on_biz_app' => true, 'platform_type' => 'CLOUD_API'])->save());
        $this->meta([
            'graph.facebook.com/*/'.self::PHONE.'*' => Http::response(['id' => self::PHONE, 'is_on_biz_app' => false, 'platform_type' => 'CLOUD_API']),
            'graph.facebook.com/*/'.self::WABA.'*' => Http::response(['id' => self::WABA]),
        ]);

        $this->checks(2);
        $this->assertNull($this->attention(), 'two checks are not enough');
        $this->checks(1);

        $this->assertSame('connected', $this->state()[2], 'polling never disconnects');
        $this->assertFalse($this->tokenRevoked());
        $attention = $this->attention();
        $this->assertSame('left_business_app', $attention['reason']);
        $this->assertEquals(['platform_type' => 'CLOUD_API', 'is_on_biz_app' => false], $attention['detail']['meta_response']);
        $this->assertSame(3, $attention['detail']['checks']);
        $audit = $this->tenantContext()->bypass(fn () => AuditLog::query()->where('action', 'whatsapp.needs_attention')->sole());
        $this->assertFalse($audit->meta['meta_response']['is_on_biz_app'], 'the audit records what Meta answered');
        $this->getJson("/api/v1/phone-numbers/{$this->number->id}")->assertJsonPath('data.attention.reason', 'left_business_app');
        Notification::assertNothingSent(); // ENGAGE_ALERTS_EMAIL not set: server log only, nothing to the client
    }

    public function test_a_refused_number_records_metas_error_code(): void
    {
        $this->connect();
        $this->meta([
            'graph.facebook.com/*/'.self::PHONE.'*' => Http::response(['error' => ['message' => 'Unsupported get request. Object does not exist or missing permissions.', 'code' => 100, 'error_subcode' => 33, 'fbtrace_id' => 'TRACE1']], 400),
            'graph.facebook.com/*/'.self::WABA.'*' => Http::response(['id' => self::WABA]),
        ]);
        $this->checks(3);

        $this->assertSame('connected', $this->state()[2]);
        $detail = $this->attention()['detail']['meta_response'];
        $this->assertSame([100, 33, 'TRACE1'], [$detail['code'], $detail['subcode'], $detail['fbtrace_id']]);
    }

    public function test_a_deregistered_number_is_flagged_and_a_healthy_answer_clears_it(): void
    {
        $this->connect();
        $this->tenantContext()->run($this->tenant, fn () => $this->number->forceFill(['platform_type' => 'CLOUD_API'])->save());
        $gone = ['id' => self::PHONE, 'platform_type' => 'NOT_APPLICABLE'];
        $this->meta([
            'graph.facebook.com/*/'.self::PHONE.'*' => Http::sequence()->push($gone)->push($gone)->push($gone)
                ->whenEmpty(Http::response(['id' => self::PHONE, 'platform_type' => 'CLOUD_API'])),
            'graph.facebook.com/*/'.self::WABA.'*' => Http::response(['id' => self::WABA]),
        ]);
        $this->checks(3);
        $this->assertSame('deregistered', $this->attention()['reason']);

        $this->checks(1);
        $this->assertNull($this->attention());
        $this->assertTrue($this->tenantContext()->bypass(fn () => AuditLog::query()->where('action', 'whatsapp.attention_cleared')->exists()));
    }

    public function test_a_field_meta_did_not_return_is_never_read_as_a_change(): void
    {
        $this->connect(OnboardingType::Coexistence);
        $this->tenantContext()->run($this->tenant, fn () => $this->number->forceFill(['is_on_biz_app' => true, 'platform_type' => 'CLOUD_API'])->save());
        $this->meta([
            'graph.facebook.com/*/'.self::PHONE.'*' => Http::response(['id' => self::PHONE, 'display_phone_number' => '+971 58 549 6310']),
            'graph.facebook.com/*/'.self::WABA.'*' => Http::response(['id' => self::WABA]),
        ]);
        $this->checks(4);

        $this->assertNull($this->attention());
        $this->tenantContext()->run($this->tenant, function () {
            $this->assertTrue($this->number->refresh()->getAttribute('is_on_biz_app'), 'missing is unknown, not false');
            $this->assertSame('CLOUD_API', $this->number->getAttribute('platform_type'));
        });
    }

    public function test_an_account_event_we_do_not_know_by_name_triggers_a_check_straight_away(): void
    {
        $this->connect();
        // Whatever Meta calls it, if our access is gone the workspace shows it within seconds of the webhook.
        $this->meta(['graph.facebook.com/*' => $this->authError(190)]);
        $this->postWebhook($this->webhookBody('account_update', ['event' => 'SOME_NEW_REMOVAL_EVENT']))->assertOk();

        $this->assertSame(['disconnected', 'access_revoked'], array_slice($this->state(), 0, 2));
    }

    // ── 5. False alarms (Brickhub, 22 Oct 2026) ────────────────────────────────────────────

    public function test_a_passing_refusal_on_a_coexistence_number_changes_nothing(): void
    {
        $this->connect(OnboardingType::Coexistence);
        $this->tenantContext()->run($this->tenant, fn () => $this->number->forceFill(['is_on_biz_app' => true, 'platform_type' => 'CLOUD_API'])->save());
        $this->meta([
            'graph.facebook.com/*/'.self::PHONE.'*' => Http::sequence()
                ->push(['error' => ['message' => 'Object does not exist or missing permissions.', 'code' => 100, 'error_subcode' => 33]], 400)
                ->push(['error' => ['message' => 'Object does not exist or missing permissions.', 'code' => 100, 'error_subcode' => 33]], 400)
                ->whenEmpty(Http::response(['id' => self::PHONE, 'is_on_biz_app' => true, 'platform_type' => 'CLOUD_API'])),
            'graph.facebook.com/*/'.self::WABA.'*' => Http::response(['id' => self::WABA]),
        ]);
        $this->checks(5);

        $this->assertSame(['connected', null, 'connected', null], $this->state());
        $this->assertNull($this->attention());
        $this->assertFalse($this->tokenRevoked());
        Notification::assertNothingSent();
    }

    public function test_a_number_still_receiving_messages_is_never_flagged(): void
    {
        $this->connect();
        $this->meta([
            'graph.facebook.com/*/'.self::PHONE.'*' => Http::response(['error' => ['message' => 'Object does not exist or missing permissions.', 'code' => 100, 'error_subcode' => 33]], 400),
            'graph.facebook.com/*/'.self::WABA.'*' => Http::response(['id' => self::WABA]),
        ]);

        for ($i = 0; $i < 4; $i++) {
            $this->postWebhook($this->webhookBody('messages', $this->inboundValue()))->assertOk(); // Meta still delivers to us
            $this->checks(1);
        }

        $this->assertNull($this->attention(), 'live webhooks prove the number still reaches us');
    }

    public function test_an_offboarded_number_keeps_the_token_and_comes_back_when_meta_answers_normally(): void
    {
        $this->connect(OnboardingType::Coexistence);
        $this->postWebhook($this->webhookBody('account_update', ['event' => 'ACCOUNT_OFFBOARDED', 'phone_number' => '971585496310']))->assertOk();
        $this->assertSame(['disconnected', 'offboarded', 'disconnected', 'offboarded'], $this->state());
        $this->assertFalse($this->tokenRevoked(), 'a single number never costs the account its credential');

        $this->meta([
            'graph.facebook.com/*/'.self::PHONE.'*' => Http::response(['id' => self::PHONE, 'is_on_biz_app' => true, 'platform_type' => 'CLOUD_API']),
            'graph.facebook.com/*/'.self::WABA.'*' => Http::response(['id' => self::WABA]),
        ]);
        $this->artisan('engage:whatsapp:verify-access')->assertSuccessful();

        $this->assertSame(['connected', null, 'connected', null], $this->state());
        $audit = $this->tenantContext()->bypass(fn () => AuditLog::query()->where('action', 'whatsapp.reconnected')->sole());
        $this->assertTrue($audit->meta['meta_response'][self::PHONE]['is_on_biz_app']);
    }

    public function test_an_account_whose_access_was_removed_is_not_brought_back_by_the_check(): void
    {
        $this->connect();
        $this->postWebhook($this->webhookBody('account_update', ['event' => 'PARTNER_REMOVED']))->assertOk();
        $this->meta(['graph.facebook.com/*' => fn () => $this->fail('a partner-removed account is not asked about')]);
        $this->artisan('engage:whatsapp:verify-access')->assertSuccessful();
        $this->assertSame('disconnected', $this->state()[0]);
    }

    /** Runs the periodic check $n times, 8 minutes apart (3 checks span 16 minutes). */
    private function checks(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->artisan('engage:whatsapp:verify-access')->assertSuccessful();
            $this->travel(8)->minutes();
        }
    }

    /** @return array{reason: string, detail: array<string, mixed>}|null */
    private function attention(): ?array
    {
        $number = $this->tenantContext()->bypass(fn () => PhoneNumber::query()->findOrFail($this->number->id));

        return $number->getAttribute('attention_reason') === null ? null
            : ['reason' => $number->getAttribute('attention_reason'), 'detail' => $number->getAttribute('attention_detail')];
    }
}
