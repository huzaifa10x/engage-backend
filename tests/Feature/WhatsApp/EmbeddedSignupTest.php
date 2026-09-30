<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Domain\Access\SystemRole;
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

    public function test_a_waba_cannot_join_a_second_workspace(): void
    {
        $this->fakeGraph();
        $this->connectNumber($this->createTenant(), self::WABA, '777777777');
        $this->actingAsMember($this->owner());

        $attempt = $this->postJson('/api/v1/whatsapp/signups')->json('data.attempt.id');

        $this->postJson("/api/v1/whatsapp/signups/{$attempt}/complete", $this->finish())
            ->assertStatus(409)->assertJsonPath('error.code', 'waba_already_connected');
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
        Http::assertSentCount(7); // exchange, debug, waba, subscribe, number, 2 × smb_app_data
        $this->assertSame(2, $this->tenantContext()->bypass(fn () => CoexistenceSyncJob::query()->whereNotNull('request_id')->count()));
    }

    public function test_cancel_records_the_abandoned_screen(): void
    {
        $this->actingAsMember($this->owner());
        $attempt = $this->postJson('/api/v1/whatsapp/signups')->json('data.attempt.id');

        $this->postJson("/api/v1/whatsapp/signups/{$attempt}/cancel", ['current_step' => 'PHONE_NUMBER_SETUP'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('PHONE_NUMBER_SETUP', $this->tenantContext()->bypass(fn () => EmbeddedSignupAttempt::query()->find($attempt)?->getAttribute('current_step')));
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
