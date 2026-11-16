<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Domain\Access\SystemRole;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Enums\OnboardingType;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** "Reconnect webhooks" on Channels, and the warning before Meta's 14-day registration deadline. */
final class ReconnectWebhooksAndRegistrationTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    /** @var list<array{id: string}> apps Meta lists on the account */
    private array $apps = [];

    /** @var list<string> */
    private array $calls = [];

    private ?string $pin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
        $this->actingAsMember($this->owner);

        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => function (Request $r) {
            $this->calls[] = $r->method().' '.parse_url($r->url(), PHP_URL_PATH);
            $path = (string) parse_url($r->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/subscribed_apps') && $r->method() === 'GET' => Http::response(['data' => array_map(fn ($a) => ['whatsapp_business_api_data' => $a], $this->apps)]),
                str_ends_with($path, '/subscribed_apps') => Http::response(['success' => true]),
                str_ends_with($path, '/register') => Http::response(['success' => true, 'pin' => $this->pin = (string) $r['pin']]),
                str_ends_with($path, '/phone_numbers') => Http::response(['data' => []]),
                str_ends_with($path, '/106540352242922') => Http::response(['id' => '106540352242922', 'platform_type' => 'CLOUD_API', 'quality_rating' => 'GREEN']),
                default => Http::response(['id' => '102290129340398', 'name' => 'Nova Fitness']),
            };
        }]);
    }

    private function waba(): WabaAccount
    {
        return $this->tenantContext()->run($this->tenant, fn () => WabaAccount::query()->firstOrFail());
    }

    public function test_refresh_notices_a_lost_subscription_and_reconnect_webhooks_restores_it(): void
    {
        $waba = $this->waba();

        // Meta no longer lists our app: Refresh says so (the card shows "Events not subscribed").
        $this->apps = [['id' => '999', 'name' => 'Other app']];
        $this->postJson("/api/v1/whatsapp/accounts/{$waba->id}/refresh")->assertOk()->assertJsonPath('data.is_subscribed_to_webhooks', false);

        // Meta accepts the subscription but still doesn't list us: a clear error, the flag stays off.
        $this->postJson("/api/v1/whatsapp/accounts/{$waba->id}/webhooks")->assertStatus(502)->assertJsonPath('error.code', 'meta_api_error');
        $this->assertFalse($this->waba()->is_subscribed_to_webhooks);

        // Now Meta lists us.
        $this->apps[] = ['id' => '1234567890', 'name' => '10X Engage'];
        $this->calls = [];
        $this->postJson("/api/v1/whatsapp/accounts/{$waba->id}/webhooks")->assertOk()->assertJsonPath('data.is_subscribed_to_webhooks', true);
        $this->assertSame(['POST /v26.0/102290129340398/subscribed_apps', 'GET /v26.0/102290129340398/subscribed_apps'], array_map(fn ($c) => preg_replace('#/v\d+\.\d+/#', '/v26.0/', $c), $this->calls));
        $this->assertSame(1, $this->tenantContext()->bypass(fn () => DB::table('audit_log')->where('action', 'whatsapp.webhooks_reconnected')->count()));
    }

    public function test_only_people_who_manage_channels_can_reconnect_webhooks_or_register(): void
    {
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Agent));
        $this->postJson("/api/v1/whatsapp/accounts/{$this->waba()->id}/webhooks")->assertForbidden();
        $this->postJson("/api/v1/whatsapp/accounts/{$this->waba()->id}/numbers/{$this->number->id}/register")->assertForbidden();
        $this->assertSame([], $this->calls);
    }

    public function test_an_unregistered_number_is_warned_before_day_14_and_register_now_fixes_it(): void
    {
        $waba = $this->waba();
        $this->tenantContext()->bypass(fn () => DB::table('phone_numbers')->where('id', $this->number->id)->update(['created_at' => now()->subDays(10)->subHour()]));

        $this->getJson('/api/v1/whatsapp/accounts')->assertOk()
            ->assertJsonPath('data.0.phone_numbers.0.registration.days_left', 4)
            ->assertJsonPath('data.0.phone_numbers.0.registration.overdue', false);

        $this->postJson("/api/v1/whatsapp/accounts/{$waba->id}/numbers/{$this->number->id}/register")->assertOk()
            ->assertJsonPath('data.phone_numbers.0.registration', null);
        $this->assertContains('/106540352242922/register', array_map(fn ($c) => preg_replace('#^POST /v[\d.]+#', '', $c), $this->calls));
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $this->pin, 'a 6-digit two-step PIN, stored encrypted');
        $this->assertNotNull($this->tenantContext()->bypass(fn () => $this->number->fresh()->pin_secret_id));

        // Past the deadline it says so; a coexistence number never needs it.
        $this->tenantContext()->bypass(fn () => DB::table('phone_numbers')->where('id', $this->number->id)->update(['registered_at' => null, 'platform_type' => null, 'created_at' => now()->subDays(15)]));
        $this->getJson('/api/v1/whatsapp/accounts')->assertJsonPath('data.0.phone_numbers.0.registration.overdue', true)
            ->assertJsonPath('data.0.phone_numbers.0.registration.days_left', 0);
        $this->tenantContext()->bypass(fn () => DB::table('phone_numbers')->where('id', $this->number->id)->update(['onboarding_type' => OnboardingType::Coexistence->value]));
        $this->getJson('/api/v1/whatsapp/accounts')->assertJsonPath('data.0.phone_numbers.0.registration', null);
    }
}
