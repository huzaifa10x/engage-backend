<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\WhatsApp\Enums\OnboardingType;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Batch 4, item 3: the business profile checklist per number (what Meta looks at for the blue tick). */
final class ProfileChecklistTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    /** @var array<string, mixed> */
    private array $profile = ['about' => 'Hi', 'description' => 'Fitness club in Dubai Marina', 'profile_picture_url' => 'https://pps.whatsapp.net/v/x.jpg',
        'websites' => ['https://novafitness.ae'], 'vertical' => 'HEALTH'];

    private string $verification = 'VERIFIED';

    private bool $down = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->actingAsMember($this->addMember($this->tenant));
        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/v27.0/*/whatsapp_business_profile*' => fn () => $this->down
                ? Http::response(['error' => ['message' => 'Service unavailable', 'code' => 2]], 503)
                : Http::response(['data' => [$this->profile]]),
            'graph.facebook.com/v27.0/102290129340398*' => fn () => Http::response(['id' => '102290129340398', 'business_verification_status' => $this->verification]),
        ]);
    }

    private function number(OnboardingType $type = OnboardingType::NewNumber, int $days = 45): PhoneNumber
    {
        $number = $this->connectNumber($this->tenant, type: $type);
        $this->tenantContext()->run($this->tenant, function () use ($number, $days) {
            WabaAccount::query()->update(['meta_business_id' => '2729063490586005', 'connected_at' => now()->subDays($days)]);
            $number->forceFill(['name_status' => 'APPROVED', 'verified_name' => 'Nova Fitness', 'registered_at' => now()->subDays($days), 'platform_type' => 'CLOUD_API'])->save();
        });

        return $number;
    }

    /** @return array<string, array<string, mixed>> */
    private function items(array $data): array
    {
        return collect($data['items'])->keyBy('key')->all();
    }

    public function test_everything_done_says_ready_to_apply_for_the_blue_tick(): void
    {
        $number = $this->number();
        $data = $this->getJson("/api/v1/phone-numbers/{$number->id}/profile-checklist")->assertOk()->json('data');

        $this->assertTrue($data['ready']);
        $this->assertSame(['done'], array_values(array_unique(array_column($data['items'], 'state'))));
        $this->assertSame(['display_name', 'photo', 'description', 'website', 'two_step', 'business_verification', 'days_on_api'], array_column($data['items'], 'key'));
        $this->assertSame('https://business.facebook.com/wa/manage/phone-numbers/?waba_id=102290129340398&business_id=2729063490586005', $data['request_url']);
        $this->assertNull($this->items($data)['photo']['fix_url']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/106540352242922/whatsapp_business_profile'));
    }

    public function test_missing_items_link_to_whatsapp_manager_and_it_is_not_ready(): void
    {
        $number = $this->number(days: 12);
        $this->profile = ['about' => '', 'description' => '', 'websites' => []];
        $this->verification = 'PENDING';
        $this->tenantContext()->run($this->tenant, fn () => $number->forceFill(['name_status' => 'PENDING_REVIEW'])->save());

        $data = $this->getJson("/api/v1/phone-numbers/{$number->id}/profile-checklist")->assertOk()->json('data');
        $items = $this->items($data);
        $this->assertFalse($data['ready']);
        foreach (['display_name', 'photo', 'description', 'website', 'business_verification', 'days_on_api'] as $key) {
            $this->assertSame('missing', $items[$key]['state'], $key);
        }
        $this->assertSame('done', $items['two_step']['state']);
        $this->assertSame('12 of 30 days', $items['days_on_api']['detail']);
        $this->assertSame('Pending', $items['business_verification']['detail']);
        $this->assertStringStartsWith('https://business.facebook.com/settings/security', (string) $items['business_verification']['fix_url']);
        $this->assertStringStartsWith('https://business.facebook.com/wa/manage/phone-numbers/', (string) $items['photo']['fix_url']);
        $this->assertNull($items['days_on_api']['fix_url']);

        // Cached for 10 minutes; "fresh" reads Meta again.
        $this->profile['profile_picture_url'] = 'https://pps.whatsapp.net/v/y.jpg';
        $this->assertSame('missing', $this->items($this->getJson("/api/v1/phone-numbers/{$number->id}/profile-checklist")->json('data'))['photo']['state']);
        $this->assertSame('done', $this->items($this->getJson("/api/v1/phone-numbers/{$number->id}/profile-checklist?fresh=1")->json('data'))['photo']['state']);
    }

    public function test_coexistence_numbers_say_eligibility_is_not_confirmed(): void
    {
        $number = $this->number(OnboardingType::Coexistence);
        $data = $this->getJson("/api/v1/phone-numbers/{$number->id}/profile-checklist")->assertOk()->json('data');

        $this->assertFalse($data['ready']);
        $this->assertSame('Eligibility not confirmed by Meta', $data['eligibility_note']);
        $this->assertSame('unknown', $this->items($data)['two_step']['state']);
    }

    public function test_meta_unreachable_shows_unknown_not_missing(): void
    {
        $number = $this->number();
        $this->down = true;
        $data = $this->getJson("/api/v1/phone-numbers/{$number->id}/profile-checklist?fresh=1")->assertOk()->json('data');
        $this->assertNotNull($data['error']);
        $items = $this->items($data);
        $this->assertSame(['unknown', 'unknown', 'unknown', 'unknown'], [$items['photo']['state'], $items['description']['state'], $items['website']['state'], $items['business_verification']['state']]);
        $this->assertFalse($data['ready']);
    }
}
