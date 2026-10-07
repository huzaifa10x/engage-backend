<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Billing\Models\SalesLead;
use App\Domain\Plans\Models\Plan;
use App\Notifications\SalesLeadNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class PublicSiteTest extends TestCase
{
    public function test_the_website_reads_the_live_plan_catalog_without_signing_in(): void
    {
        $res = $this->getJson('/api/v1/public/plans')->assertOk()
            ->assertJsonPath('data.trial.plan_key', 'pro')->assertJsonPath('data.trial.days', 14)
            ->assertJsonPath('data.vat.percent', 5);

        $plans = collect($res->json('data.plans'))->keyBy('key');
        $this->assertSame(['free', 'starter', 'growth', 'pro', 'enterprise'], $plans->keys()->all());

        // Prices come from the published plan version, in minor units.
        $growth = $plans['growth'];
        $version = Plan::byKey('growth')->activeVersionOrFail();
        $this->assertSame($version->price_monthly_minor, $growth['price_monthly_minor']);
        $this->assertSame($version->price_yearly_minor, $growth['price_yearly_minor']);
        $this->assertSame('USD', $growth['currency']);
        $this->assertTrue($plans['free']['free']);
        $this->assertTrue($plans['enterprise']['custom_price']);
        $this->assertNull($plans['enterprise']['price_monthly_minor']);

        // Limits and features, with labels, straight from the catalog.
        $features = collect($growth['features'])->keyBy('key');
        $this->assertSame('WhatsApp numbers', $features['whatsapp_numbers']['label']);
        $this->assertSame(3, $features['whatsapp_numbers']['limit']);
        $this->assertSame(30, $features['messages_per_second']['limit']);
        $this->assertTrue($features['broadcasts']['enabled']);
        $this->assertFalse(collect($plans['free']['features'])->keyBy('key')['broadcasts']['enabled']);

        // Nothing internal leaks: no Stripe identifiers, no version ids.
        $this->assertStringNotContainsString('stripe', strtolower((string) $res->getContent()));

        // A hidden or inactive plan is not listed.
        Plan::byKey('starter')->forceFill(['is_active' => false])->save();
        Cache::forget('public:plan-catalog');
        $this->assertNotContains('starter', collect($this->getJson('/api/v1/public/plans')->json('data.plans'))->pluck('key')->all());
    }

    public function test_the_demo_form_is_stored_and_emailed_to_sales(): void
    {
        Notification::fake();
        config(['engage.sales_email' => 'sales@10xdigital.ae']);

        $this->postJson('/api/v1/public/leads', ['name' => 'Sara Ahmed', 'email' => 'not-an-email'])->assertStatus(422);
        $this->postJson('/api/v1/public/leads', [
            'name' => 'Sara Ahmed', 'email' => 'sara@palmestates.ae', 'company' => 'Palm Estates', 'team_size' => '6–20 (agency)', 'topic' => 'demo', 'source' => '/demo',
        ])->assertCreated()->assertJsonPath('data.status', 'received');

        $lead = SalesLead::query()->firstOrFail();
        $this->assertSame('Palm Estates', $lead->company);
        Notification::assertSentOnDemand(SalesLeadNotification::class, fn (SalesLeadNotification $n, array $channels, object $notifiable) => $notifiable->routes['mail'] === 'sales@10xdigital.ae' && $n->lead->is($lead));

        // A bot that fills the hidden field gets a normal answer, and nothing is kept or sent.
        Notification::fake();
        $this->postJson('/api/v1/public/leads', ['name' => 'Bot', 'email' => 'bot@example.com', 'website' => 'http://x'])->assertCreated();
        $this->assertSame(1, SalesLead::query()->count());
        Notification::assertNothingSent();
    }
}
