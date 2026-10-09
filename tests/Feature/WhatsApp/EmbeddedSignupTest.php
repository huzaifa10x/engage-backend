<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Domain\Access\SystemRole;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\Models\Feature;
use App\Domain\Plans\Models\TenantEntitlementOverride;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Enums\CoexistenceStatus;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Models\CoexistenceSyncJob;
use App\Domain\WhatsApp\Models\EmbeddedSignupAttempt;
use App\Domain\WhatsApp\Models\MetaAccessToken;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Infrastructure\Secrets\SecretStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

final class EmbeddedSignupTest extends TestCase
{
    use InteractsWithWhatsapp;

    private const WABA = '102290129340398';

    private const PHONE = '106540352242922';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
    }

    private ?Tenant $tenant = null;

    private function owner(string $plan = 'pro'): TenantMembership
    {
        $this->tenant = $this->createTenant(['name' => 'Nova Fitness']);
        $this->subscribe($this->tenant, $plan);

        return $this->addMember($this->tenant);
    }

    /** @return array<string, mixed> */
    private function finish(string $event = 'FINISH'): array
    {
        return ['code' => 'AQB-one-time-code', 'event' => $event, 'waba_id' => self::WABA, 'phone_number_id' => self::PHONE, 'business_id' => '2729063490586005'];
    }

    public function test_owner_connects_a_number_end_to_end(): void
    {
        $this->fakeGraph();
        $owner = $this->owner();
        $this->actingAsMember($owner);

        $start = $this->postJson('/api/v1/whatsapp/signups')
            ->assertCreated()
            ->assertJsonPath('data.launch.config_id', '555000')
            ->assertJsonPath('data.launch.graph_version', 'v25.0')
            ->assertJsonPath('data.launch.login_options.response_type', 'code');

        $attempt = $start->json('data.attempt.id');

        $this->postJson("/api/v1/whatsapp/signups/{$attempt}/complete", $this->finish())
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $number = $this->tenantContext()->bypass(fn () => PhoneNumber::query()->where('phone_number_id', self::PHONE)->first());
        $this->assertSame(PhoneNumberStatus::Connected, $number?->status);
        $this->assertSame('Nova Fitness', $number?->verified_name);
        $this->assertSame('+971585496310', $number?->getAttribute('e164'));
        $this->assertSame(80, $number?->max_mps);

        $waba = $this->tenantContext()->bypass(fn () => WabaAccount::query()->where('waba_id', self::WABA)->first());
        $this->assertTrue($waba?->is_subscribed_to_webhooks);
        $this->getJson("/api/v1/whatsapp/signups/{$attempt}")->assertOk()->assertJsonPath('data.steps.verify_subscription.state', 'done');
        $this->assertSame('Nova Fitness LLC', $waba?->business_name);

        // Token is only in the secret store, encrypted.
        $token = $this->tenantContext()->bypass(fn () => MetaAccessToken::query()->find($waba?->access_token_id));
        $this->assertSame('EAAG-business-token', app(SecretStore::class)->get((string) $token?->secret_id));
        $this->assertFalse($this->tenantContext()->bypass(fn () => DB::table('secrets')->where('ciphertext', 'like', '%EAAG%')->exists()));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/register')
            && preg_match('/^\d{6}$/', (string) ($r['pin'] ?? '')) === 1
            && str_contains($r->url(), 'appsecret_proof='));

        $this->getJson('/api/v1/phone-numbers')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.onboarding_type', 'new_number');
    }

    public function test_waba_must_be_granted_by_the_exchanged_token(): void
    {
        $this->fakeGraph(grantedWabas: ['999999999']);
        $this->actingAsMember($this->owner());

        $attempt = $this->postJson('/api/v1/whatsapp/signups')->json('data.attempt.id');

        $this->postJson("/api/v1/whatsapp/signups/{$attempt}/complete", $this->finish())
            ->assertStatus(410)->assertJsonPath('error.code', 'signup_session_invalid');

        $this->assertFalse($this->tenantContext()->bypass(fn () => WabaAccount::query()->exists()));
    }

    public function test_number_subscribed_to_another_app_is_blocked_with_the_app_name(): void
    {
        $this->fakeGraph(subscribedApps: [['id' => '777000111', 'name' => 'AiSensy', 'link' => 'https://www.facebook.com/games/?app_id=777000111']]);
        $this->actingAsMember($this->owner());

        $attempt = $this->postJson('/api/v1/whatsapp/signups')->json('data.attempt.id');

        $response = $this->postJson("/api/v1/whatsapp/signups/{$attempt}/complete", $this->finish())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'number_subscribed_elsewhere')
            ->assertJsonPath('error.details.apps.0.name', 'AiSensy');
        $this->assertStringContainsString('AiSensy', (string) $response->json('error.message'));

        // Nothing was stored, subscribed or registered.
        $this->assertFalse($this->tenantContext()->bypass(fn () => WabaAccount::query()->exists()));
        $this->assertFalse($this->tenantContext()->bypass(fn () => PhoneNumber::query()->exists()));
        $this->assertFalse($this->tenantContext()->bypass(fn () => MetaAccessToken::query()->exists()));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/subscribed_apps'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/register'));

        $this->getJson("/api/v1/whatsapp/signups/{$attempt}")->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error.code', 'number_subscribed_elsewhere')
            ->assertJsonPath('data.error.apps.0.name', 'AiSensy');
        $this->getJson('/api/v1/phone-numbers')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_number_connected_by_another_environment_of_our_app_is_blocked(): void
    {
        // Production and staging share one Meta app: the app is subscribed, but not by us.
        $this->fakeGraph(subscribedApps: [['id' => '1234567890', 'name' => '10X Engage']]);
        $this->actingAsMember($this->owner());

        $attempt = $this->postJson('/api/v1/whatsapp/signups')->json('data.attempt.id');

        $this->postJson("/api/v1/whatsapp/signups/{$attempt}/complete", $this->finish())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'number_subscribed_elsewhere')
            ->assertJsonPath('error.details.same_app', true)
            ->assertJsonPath('error.details.apps.0.name', '10X Engage');

        $this->assertFalse($this->tenantContext()->bypass(fn () => WabaAccount::query()->exists()));
        $this->assertFalse($this->tenantContext()->bypass(fn () => PhoneNumber::query()->exists()));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/subscribed_apps'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/register'));

        $this->getJson("/api/v1/whatsapp/signups/{$attempt}")->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error.same_app', true);
    }

    public function test_rerunning_signup_for_a_number_connected_here_still_works(): void
    {
        // Our app is subscribed because THIS installation connected the account (token refresh).
        $this->fakeGraph(subscribedApps: [['id' => '1234567890', 'name' => '10X Engage']]);
        $owner = $this->owner();
        $this->connectNumber($this->tenant, self::WABA, self::PHONE);
        $this->actingAsMember($owner);

        $attempt = $this->postJson('/api/v1/whatsapp/signups')->json('data.attempt.id');

        $this->postJson("/api/v1/whatsapp/signups/{$attempt}/complete", $this->finish())
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.steps.check_other_apps.state', 'done')
            ->assertJsonPath('data.steps.verify_subscription.state', 'done');
    }

    public function test_allow_listed_apps_do_not_block_onboarding(): void
    {
        config(['engage.meta.allowed_other_app_ids' => ['777000111']]);
        $this->fakeGraph(subscribedApps: [['id' => '777000111', 'name' => 'Our second app']]);
        $this->actingAsMember($this->owner());

        $attempt = $this->postJson('/api/v1/whatsapp/signups')->json('data.attempt.id');

        $this->postJson("/api/v1/whatsapp/signups/{$attempt}/complete", $this->finish())
            ->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_a_waba_cannot_join_a_second_workspace(): void
    {
        $this->fakeGraph();
        $this->connectNumber($this->createTenant(), self::WABA, '777777777');
        $this->actingAsMember($this->owner());

        $attempt = $this->postJson('/api/v1/whatsapp/signups')->json('data.attempt.id');

        $this->postJson("/api/v1/whatsapp/signups/{$attempt}/complete", $this->finish())
            ->assertStatus(409)->assertJsonPath('error.code', 'waba_already_connected');
    }

    public function test_an_account_that_is_only_disconnected_in_another_workspace_can_be_connected_here(): void
    {
        $this->fakeGraph();

        // The old workspace had this account and number connected, with a conversation, and then disconnected it.
        $old = $this->createTenant(['name' => 'Old Workspace']);
        $this->subscribe($old, 'pro');
        $oldOwner = $this->addMember($old);
        $oldNumber = $this->connectNumber($old, self::WABA, self::PHONE);
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue()))->assertOk();
        $this->actingAsMember($oldOwner);
        $wabaRow = $this->getJson('/api/v1/whatsapp/accounts')->json('data.0.id');
        $this->deleteJson("/api/v1/whatsapp/accounts/{$wabaRow}")->assertSuccessful();

        // The same business now connects it to a new workspace: no "already connected to another workspace".
        $this->actingAsMember($this->owner());
        $attempt = $this->postJson('/api/v1/whatsapp/signups')->json('data.attempt.id');
        $this->postJson("/api/v1/whatsapp/signups/{$attempt}/complete", $this->finish())->assertOk()->assertJsonPath('data.status', 'completed');
        $this->getJson('/api/v1/whatsapp/accounts')->assertOk()->assertJsonPath('data.0.waba_id', self::WABA)->assertJsonPath('data.0.status', 'connected')
            ->assertJsonPath('data.0.phone_numbers.0.phone_number_id', self::PHONE);

        // New messages for that number now arrive in the new workspace …
        $value = $this->inboundValue(waId: '971502223344');
        $value['messages'][0]['id'] = 'wamid.AFTER_MOVE';
        $this->postWebhook($this->webhookBody('messages', $value))->assertOk();
        $this->actingAsMember($this->addMember($this->tenant));
        $this->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(1, 'data');

        // … and the old workspace keeps its history, shown on a disconnected number.
        $this->actingAsMember($oldOwner);
        $this->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.phone_number.status', 'disconnected');
        $this->getJson("/api/v1/phone-numbers/{$oldNumber->id}")->assertOk()->assertJsonPath('data.status', 'disconnected');
    }

    public function test_plan_number_limit_is_enforced_at_completion(): void
    {
        $this->fakeGraph();
        $owner = $this->owner('free'); // Free: 1 number
        $this->connectNumber($this->tenant, '300000000001', '300000000002');
        $this->actingAsMember($owner);

        $start = $this->postJson('/api/v1/whatsapp/signups')->assertCreated()->assertJsonPath('data.numbers.used', 1)->assertJsonPath('data.numbers.limit', 1);

        $this->postJson("/api/v1/whatsapp/signups/{$start->json('data.attempt.id')}/complete", $this->finish())
            ->assertStatus(402)->assertJsonPath('error.code', 'plan_limit_reached');
    }

    public function test_agents_cannot_connect_numbers(): void
    {
        $tenant = $this->createTenant();
        $this->actingAsMember($this->addMember($tenant, SystemRole::Agent));

        $this->postJson('/api/v1/whatsapp/signups')->assertForbidden();
    }

    public function test_failed_code_exchange_fails_the_attempt(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => [
            'message' => 'This authorization code has expired.', 'type' => 'OAuthException', 'code' => 100, 'fbtrace_id' => 'AbC123',
        ]], 400)]);
        $this->actingAsMember($this->owner());

        $attempt = $this->postJson('/api/v1/whatsapp/signups')->json('data.attempt.id');

        $this->postJson("/api/v1/whatsapp/signups/{$attempt}/complete", $this->finish())
            ->assertStatus(502)->assertJsonPath('error.code', 'meta_api_error');

        $this->getJson("/api/v1/whatsapp/signups/{$attempt}")->assertJsonPath('data.status', 'failed');
    }

    public function test_coexistence_is_off_unless_enabled_and_entitled(): void
    {
        $this->actingAsMember($this->owner());

        $this->postJson('/api/v1/whatsapp/signups', ['coexistence' => true])
            ->assertForbidden()->assertJsonPath('error.code', 'feature_not_available');
    }

    public function test_coexistence_skips_registration_and_starts_both_syncs(): void
    {
        $this->configureMeta(coexistence: true);
        $this->fakeGraph();
        $this->actingAsMember($this->owner());

        $start = $this->postJson('/api/v1/whatsapp/signups', ['coexistence' => true])
            ->assertCreated()
            ->assertJsonPath('data.launch.login_options.extras.featureType', 'whatsapp_business_app_onboarding');

        $this->postJson("/api/v1/whatsapp/signups/{$start->json('data.attempt.id')}/complete", $this->finish('FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING'))
            ->assertOk()->assertJsonPath('data.status', 'completed');

        $number = $this->tenantContext()->bypass(fn () => PhoneNumber::query()->where('phone_number_id', self::PHONE)->first());
        $this->assertSame('coexistence', $number?->onboarding_type->value);
        $this->assertSame(CoexistenceStatus::HistorySyncing, $number?->coexistence_status);
        $this->assertSame(20, $number?->max_mps);
        $this->assertNotNull($number?->app_sync_expires_at);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/register'));
        // exchange, debug, subscribed-apps check, waba, subscribe, verify subscription, number, 2 × smb_app_data, template sync
        Http::assertSentCount(10);
        $this->assertSame(2, $this->tenantContext()->bypass(fn () => CoexistenceSyncJob::query()->whereNotNull('request_id')->count()));
    }

    public function test_coexistence_launches_with_its_own_configuration_id(): void
    {
        $this->configureMeta(coexistence: true);
        config(['engage.meta.coexistence_config_id' => '2159163541380544']);
        $this->actingAsMember($this->owner());

        // The WhatsApp Business app flow uses the coexistence configuration, with Meta's two required extras …
        $this->postJson('/api/v1/whatsapp/signups', ['coexistence' => true])->assertCreated()
            ->assertJsonPath('data.attempt.flow', 'coexistence')
            ->assertJsonPath('data.launch.config_id', '2159163541380544')
            ->assertJsonPath('data.launch.login_options.config_id', '2159163541380544')
            ->assertJsonPath('data.launch.login_options.response_type', 'code')
            ->assertJsonPath('data.launch.login_options.extras.featureType', 'whatsapp_business_app_onboarding')
            ->assertJsonPath('data.launch.login_options.extras.sessionInfoVersion', '3');

        // … while a normal number keeps the standard one and carries no coexistence extras.
        $this->postJson('/api/v1/whatsapp/signups', ['coexistence' => false])->assertCreated()
            ->assertJsonPath('data.launch.config_id', '555000')
            ->assertJsonMissingPath('data.launch.login_options.extras.featureType');
    }

    public function test_chat_history_is_imported_on_the_free_plan_too(): void
    {
        // Paid plans are covered by test_coexistence_skips_registration_and_starts_both_syncs (Pro).
        $this->configureMeta(coexistence: true);
        $this->fakeGraph();
        $this->actingAsMember($this->owner('free'));

        $start = $this->postJson('/api/v1/whatsapp/signups', ['coexistence' => true])->assertCreated();
        $this->postJson("/api/v1/whatsapp/signups/{$start->json('data.attempt.id')}/complete", $this->finish('FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING'))
            ->assertOk()->assertJsonPath('data.status', 'completed');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/smb_app_data') && $r['sync_type'] === 'smb_app_state_sync');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/smb_app_data') && $r['sync_type'] === 'history');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/register'));

        $number = $this->tenantContext()->bypass(fn () => PhoneNumber::query()->where('phone_number_id', self::PHONE)->first());
        $this->assertSame(CoexistenceStatus::HistorySyncing, $number?->coexistence_status);
        $this->assertSame(2, $this->tenantContext()->bypass(fn () => CoexistenceSyncJob::query()->whereNotNull('request_id')->count()));
    }

    public function test_one_workspace_can_be_switched_off_and_stalled_imports_are_closed(): void
    {
        $this->configureMeta(coexistence: true);
        $this->fakeGraph();
        $owner = $this->owner();
        $this->actingAsMember($owner);

        $start = $this->postJson('/api/v1/whatsapp/signups', ['coexistence' => true])->assertCreated();
        $this->postJson("/api/v1/whatsapp/signups/{$start->json('data.attempt.id')}/complete", $this->finish('FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING'))->assertOk();
        $number = fn () => $this->tenantContext()->bypass(fn () => PhoneNumber::query()->where('phone_number_id', self::PHONE)->firstOrFail());
        $this->assertSame(CoexistenceStatus::HistorySyncing, $number()->coexistence_status);

        // Inside the window, or with data still arriving, nothing is touched.
        $this->travel(30)->hours();
        $this->artisan('engage:coexistence:watch')->assertSuccessful();
        $this->assertSame(CoexistenceStatus::HistorySyncing, $number()->coexistence_status);

        // Requested but silent for three days → closed, so the inbox stops showing "syncing".
        $this->travel(3)->days();
        $this->artisan('engage:coexistence:watch')->assertSuccessful();
        $this->assertSame(CoexistenceStatus::Synced, $number()->coexistence_status);

        // Never requested inside Meta's 24 hours → the import can no longer start.
        $this->tenantContext()->bypass(function () use ($number): void {
            CoexistenceSyncJob::query()->where('phone_number_id', $number()->id)->update(['request_id' => null]);
            $number()->forceFill(['coexistence_status' => CoexistenceStatus::SyncPending, 'app_sync_expires_at' => now()->subHour()])->save();
        });
        $this->artisan('engage:coexistence:watch')->assertSuccessful();
        $this->assertSame(CoexistenceStatus::SyncFailed, $number()->coexistence_status);

        // The per-workspace switch: an entitlement override turns coexistence off for this tenant only.
        $this->tenantContext()->bypass(function (): void {
            $feature = Feature::query()->where('key', 'coexistence')->firstOrFail();
            TenantEntitlementOverride::query()->create(['tenant_id' => $this->tenant?->id, 'feature_id' => $feature->id, 'enabled' => false, 'reason' => 'Meta behaviour change']);
        });
        app(EntitlementService::class)->forget($this->tenant);
        $this->actingAsMember($owner);
        $this->postJson('/api/v1/whatsapp/signups', ['coexistence' => true])->assertForbidden()->assertJsonPath('error.code', 'feature_not_available');
        $this->postJson('/api/v1/whatsapp/signups', ['coexistence' => false])->assertCreated();
    }

    public function test_cancel_records_the_abandoned_screen(): void
    {
        $this->actingAsMember($this->owner());
        $attempt = $this->postJson('/api/v1/whatsapp/signups')->json('data.attempt.id');

        $this->postJson("/api/v1/whatsapp/signups/{$attempt}/cancel", ['current_step' => 'PHONE_NUMBER_SETUP'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('PHONE_NUMBER_SETUP', $this->tenantContext()->bypass(fn () => EmbeddedSignupAttempt::query()->find($attempt)?->getAttribute('current_step')));
    }

    public function test_a_second_completion_of_the_same_attempt_is_rejected(): void
    {
        $this->fakeGraph();
        $this->actingAsMember($this->owner());
        $attempt = $this->postJson('/api/v1/whatsapp/signups')->json('data.attempt.id');

        $this->postJson("/api/v1/whatsapp/signups/{$attempt}/complete", $this->finish())->assertOk();
        $this->postJson("/api/v1/whatsapp/signups/{$attempt}/complete", $this->finish())
            ->assertStatus(410)->assertJsonPath('error.code', 'signup_session_invalid');

        $this->getJson("/api/v1/whatsapp/signups/{$attempt}")->assertJsonPath('data.status', 'completed');
        Http::assertSentCount(9); // exchanged exactly once (the second completion sends nothing)
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_contains($r->url(), '/oauth/access_token')));
    }

    public function test_permanent_registration_failure_frees_the_plan_slot(): void
    {
        // Registered first so it wins over the generic fakes (e.g. a number with another BSP's PIN).
        Http::fake(['graph.facebook.com/v25.0/'.self::PHONE.'/register*' => Http::response(['error' => [
            'message' => 'Two step verification PIN Mismatch', 'code' => 133005, 'fbtrace_id' => 'X1',
        ]], 400)]);
        $this->fakeGraph();
        $this->actingAsMember($this->owner('free'));
        $attempt = $this->postJson('/api/v1/whatsapp/signups')->json('data.attempt.id');

        $this->postJson("/api/v1/whatsapp/signups/{$attempt}/complete", $this->finish())
            ->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error.code', '133005');

        $number = $this->tenantContext()->bypass(fn () => PhoneNumber::query()->where('phone_number_id', self::PHONE)->first());
        $this->assertSame(PhoneNumberStatus::Disconnected, $number?->status);

        // Slot is free again: the Free plan can start (and complete) another signup.
        $this->postJson('/api/v1/whatsapp/signups')->assertCreated()->assertJsonPath('data.numbers.used', 0);
    }

    public function test_disconnect_unsubscribes_and_shreds_the_token(): void
    {
        $this->fakeGraph();
        $owner = $this->owner();
        $number = $this->connectNumber($this->tenant);
        $this->actingAsMember($owner);

        $this->deleteJson("/api/v1/whatsapp/accounts/{$number->waba_account_id}")->assertNoContent();

        $this->assertSame(PhoneNumberStatus::Disconnected, $this->tenantContext()->bypass(fn () => PhoneNumber::query()->find($number->id))?->status);
        $this->assertTrue($this->tenantContext()->bypass(fn () => DB::table('secrets')->whereNotNull('destroyed_at')->where('ciphertext', '')->exists()));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/subscribed_apps'));
    }
}
