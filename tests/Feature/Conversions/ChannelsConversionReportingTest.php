<?php

declare(strict_types=1);

namespace Tests\Feature\Conversions;

use App\Domain\Conversions\ConversionsAccess;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Conversions to Meta, 3b: "Conversion reporting: Enabled / Not enabled" and "Reconnect to enable" in Channels. */
final class ChannelsConversionReportingTest extends TestCase
{
    use InteractsWithWhatsapp;

    private const WABA = '102290129340398';

    private const PHONE = '106540352242922';

    private Tenant $tenant;

    private TenantMembership $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        config(['engage.meta.embedded_signup_config_id' => '555000', 'engage.meta.events_config_id' => '777000']);
        $this->tenant = $this->createTenant(['name' => 'Nova Fitness']);
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->actingAsMember($this->owner);
    }

    private function allow(bool $on = true): void
    {
        $this->tenantContext()->run($this->tenant, fn () => ConversionsAccess::set($this->tenant, $on));
    }

    /** @param list<string> $scopes */
    private function fakeDebugToken(array $scopes): void
    {
        Http::fake(['graph.facebook.com/*/debug_token*' => Http::response(['data' => ['is_valid' => true, 'type' => 'SYSTEM_USER', 'scopes' => $scopes,
            'app_id' => '1234567890', 'user_id' => '9988776655', 'expires_at' => 0,
            'granular_scopes' => array_map(fn ($s) => ['scope' => $s, 'target_ids' => [self::WABA]], $scopes)]])]);
    }

    /** @return array<string, mixed> */
    private function finish(): array
    {
        return ['code' => 'AQB-one-time-code', 'event' => 'FINISH', 'waba_id' => self::WABA, 'phone_number_id' => self::PHONE, 'business_id' => '2729063490586005'];
    }

    public function test_a_workspace_without_the_flag_sees_no_change(): void
    {
        $this->connectNumber($this->tenant);
        $this->getJson('/api/v1/whatsapp/accounts')->assertOk()->assertJsonPath('data.0.conversions', null);
        $this->postJson('/api/v1/whatsapp/signups', ['conversions' => true])->assertForbidden();
        $this->postJson('/api/v1/whatsapp/signups')->assertCreated()->assertJsonPath('data.launch.config_id', '555000');
    }

    public function test_status_comes_from_the_stored_debug_token_check(): void
    {
        $this->connectNumber($this->tenant);
        $this->allow();
        $this->getJson('/api/v1/whatsapp/accounts')->assertJsonPath('data.0.conversions.status', 'unknown')
            ->assertJsonPath('data.0.conversions.can_reconnect', true);

        $this->tenantContext()->bypass(fn () => WabaAccount::query()->update(['events_permission' => false, 'events_permission_checked_at' => now()]));
        $this->getJson('/api/v1/whatsapp/accounts')->assertJsonPath('data.0.conversions.status', 'not_enabled');

        $this->tenantContext()->bypass(fn () => WabaAccount::query()->update(['events_permission' => true]));
        $this->getJson('/api/v1/whatsapp/accounts')->assertJsonPath('data.0.conversions.status', 'enabled');

        config(['engage.meta.events_config_id' => null]);
        $this->getJson('/api/v1/whatsapp/accounts')->assertJsonPath('data.0.conversions.can_reconnect', false);
        $this->postJson('/api/v1/whatsapp/signups', ['conversions' => true])->assertForbidden();
    }

    public function test_reconnect_to_enable_uses_the_new_config_and_stores_the_permission_after_signup(): void
    {
        $this->allow();
        $this->fakeDebugToken(['whatsapp_business_management', 'whatsapp_business_messaging', 'whatsapp_business_manage_events']);
        $this->fakeGraph(self::WABA, self::PHONE);

        $attempt = $this->postJson('/api/v1/whatsapp/signups', ['conversions' => true])->assertCreated()
            ->assertJsonPath('data.launch.config_id', '777000')
            ->assertJsonPath('data.launch.login_options.config_id', '777000')
            ->json('data.attempt.id');

        $this->postJson("/api/v1/whatsapp/signups/{$attempt}/complete", $this->finish())->assertOk()->assertJsonPath('data.status', 'completed');

        $this->getJson('/api/v1/whatsapp/accounts')->assertJsonPath('data.0.conversions.status', 'enabled');
        $this->assertTrue($this->tenantContext()->bypass(fn () => WabaAccount::query()->value('events_permission')));
    }

    public function test_refresh_rechecks_the_permission_only_for_flagged_workspaces(): void
    {
        $this->connectNumber($this->tenant);
        $this->fakeDebugToken(['whatsapp_business_management', 'whatsapp_business_messaging']);
        $this->fakeGraph(self::WABA, self::PHONE);
        $account = $this->getJson('/api/v1/whatsapp/accounts')->json('data.0.id');

        $this->postJson("/api/v1/whatsapp/accounts/{$account}/refresh")->assertOk()->assertJsonPath('data.conversions', null);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'debug_token'));

        $this->allow();
        $this->postJson("/api/v1/whatsapp/accounts/{$account}/refresh")->assertOk()->assertJsonPath('data.conversions.status', 'not_enabled');
    }
}
