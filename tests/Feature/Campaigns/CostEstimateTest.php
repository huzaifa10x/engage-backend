<?php

declare(strict_types=1);

namespace Tests\Feature\Campaigns;

use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\MemberNotification;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Batch 4, item 2: Meta's cost, estimated before sending (Campaigns and Templates). */
final class CostEstimateTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->tenantContext()->bypass(fn () => $this->tenant->forceFill(['settings' => ['compliance' => ['quiet_hours_enabled' => false]]])->save());
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
        $this->actingAsMember($this->owner);

        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/v27.0/102290129340398/message_templates*' => Http::response(['data' => [
                ['id' => '900001', 'name' => 'big_sale', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'MARKETING',
                    'components' => [['type' => 'BODY', 'text' => 'Our big sale starts today.']]],
                ['id' => '900002', 'name' => 'order_update', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'UTILITY',
                    'components' => [['type' => 'BODY', 'text' => 'Your order is on its way.']]],
            ]]),
            'graph.facebook.com/v27.0/*/messages*' => fn () => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '0']], 'messages' => [['id' => 'wamid.C'.Str::random(8)]]]),
        ]);
        $this->postJson('/api/v1/templates/sync')->assertOk();

        // Customers in three countries; one opted out; one number that is not valid in its country.
        foreach (['+971501112201', '+971501112202', '+966501112203', '+919811122204', '+971501112205'] as $i => $phone) {
            $this->postJson('/api/v1/contacts', ['phone' => $phone, 'name' => "Guest {$i}", 'tags' => ['Sale']])->assertCreated();
        }
        $this->tenantContext()->run($this->tenant, function () {
            Contact::query()->update(['consent_state' => 'opted_in']);
            Contact::query()->where('wa_id', '971501112205')->update(['consent_state' => 'opted_out']);
            Contact::query()->create(['wa_id' => '97112', 'name' => 'Broken number', 'tags' => ['Sale'], 'consent_state' => 'opted_in']);
        });
    }

    private function template(string $name): string
    {
        return (string) collect($this->getJson('/api/v1/templates')->json('data'))->firstWhere('name', $name)['id'];
    }

    private function audience(string $template): array
    {
        return (array) $this->getJson("/api/v1/campaigns/audience?audience_tag=Sale&template_id={$template}&phone_number_id={$this->number->id}")->assertOk()->json('data');
    }

    public function test_marketing_estimate_is_recipients_by_country_times_metas_rate_without_opted_out_or_invalid_numbers(): void
    {
        $data = $this->audience($this->template('big_sale'));
        $estimate = $data['cost_estimate'];

        $this->assertSame('Estimate. Paid by you directly to Meta.', $estimate['label']);
        $this->assertSame('USD', $estimate['currency']);
        $this->assertSame('2026-10-01', $estimate['rates_effective_from']);
        $this->assertSame(4, $estimate['recipients']);
        $this->assertSame(1, $estimate['excluded_invalid']);
        $this->assertSame(1, $data['excluded']['opted_out']);
        // 2 × UAE 0.0576 + Saudi Arabia 0.0576 + India 0.0118 = 0.1846
        $this->assertSame(0.18, $estimate['total']);
        $rows = collect($estimate['by_country'])->keyBy('country');
        $this->assertSame(['United Arab Emirates', 2, 0.0576, 0.1152], [$rows['AE']['market'], $rows['AE']['recipients'], $rows['AE']['rate'], $rows['AE']['cost']]);
        $this->assertSame(['Saudi Arabia', 1, 0.0576], [$rows['SA']['market'], $rows['SA']['recipients'], $rows['SA']['rate']]);
        $this->assertSame(['India', 1, 0.0118], [$rows['IN']['market'], $rows['IN']['recipients'], $rows['IN']['rate']]);
    }

    public function test_utility_is_free_inside_the_customer_service_window(): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue([], '971501112201', 'AE.971501112201', 'Guest 0')))->assertOk();

        $estimate = $this->audience($this->template('order_update'))['cost_estimate'];
        $this->assertSame('UTILITY', $estimate['category']);
        $this->assertSame(1, $estimate['free_in_window']);
        // Utility also goes to the contact who opted out of marketing. Charged: UAE 0202 and 0205 (0201 is in
        // the window, free), Saudi Arabia, India: 2 × 0.0157 + 0.0107 + 0.0014 = 0.0435.
        $this->assertSame(4, $estimate['recipients']);
        $this->assertSame(0.04, $estimate['total']);
    }

    public function test_the_estimate_is_saved_on_the_campaign_with_the_date_of_the_rates(): void
    {
        $id = (string) $this->postJson('/api/v1/campaigns', ['name' => 'Big sale', 'phone_number_id' => $this->number->id, 'template_id' => $this->template('big_sale'), 'audience_tag' => 'Sale'])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/v1/campaigns/{$id}/launch")->assertOk();

        $this->getJson("/api/v1/campaigns/{$id}")->assertOk()
            ->assertJsonPath('data.cost_estimate.total', 0.18)->assertJsonPath('data.cost_estimate.rates_effective_from', '2026-10-01')
            ->assertJsonPath('data.cost_estimate.recipients', 4)
            ->assertJsonPath('data.stats.sent', 4)->assertJsonPath('data.stats.skipped', 2); // opted out, and the number that is not valid: it never reaches Meta
        $this->assertContains('Not a valid WhatsApp number', $this->tenantContext()->run($this->tenant,
            fn () => DB::table('campaign_recipients')->where('campaign_id', $id)->where('status', 'skipped')->pluck('reason')->all()));
    }

    public function test_a_category_change_by_meta_warns_and_reprices(): void
    {
        $template = $this->template('order_update');
        $this->assertSame(0.06, $this->audience($template)['cost_estimate']['total']); // utility: 3 × 0.0157 + 0.0107 + 0.0014

        $this->postWebhook($this->webhookBody('template_category_update', ['message_template_id' => '900002', 'message_template_name' => 'order_update',
            'message_template_language' => 'en', 'previous_category' => 'UTILITY', 'new_category' => 'MARKETING']))->assertOk();

        $data = $this->audience($template);
        $this->assertSame('MARKETING', $data['cost_estimate']['category']);
        $this->assertSame(0.18, $data['cost_estimate']['total']); // now the marketing rate, and opted-out contacts are left out
        $this->assertSame(['from' => 'UTILITY', 'to' => 'MARKETING'], array_intersect_key($data['category_change'], ['from' => 1, 'to' => 1]));

        $show = $this->getJson("/api/v1/templates/{$template}")->assertOk()->assertJsonPath('data.category', 'MARKETING')
            ->assertJsonPath('data.category_change.from', 'UTILITY')->assertJsonPath('data.pricing.rates_effective_from', '2026-10-01')->json('data.pricing');
        $uae = collect($show['markets'])->firstWhere('market', 'United Arab Emirates');
        $this->assertSame(0.0576, $uae['rate']);
        $this->assertContains('AE', $uae['countries']);

        $bell = $this->tenantContext()->bypass(fn () => MemberNotification::query()->where('membership_id', $this->owner->id)->where('type', 'template_category')->pluck('title')->all());
        $this->assertSame(['Meta re-categorised order_update (en) as Marketing'], $bell);

        // Meta repeats the webhook: nothing new.
        $this->postWebhook($this->webhookBody('template_category_update', ['message_template_id' => '900002', 'message_template_name' => 'order_update',
            'message_template_language' => 'en', 'previous_category' => 'UTILITY', 'new_category' => 'MARKETING']))->assertOk();
        $this->assertSame(1, $this->tenantContext()->bypass(fn () => MemberNotification::query()->where('type', 'template_category')->count()));
    }

    public function test_a_new_rate_card_is_imported_and_used_from_its_date(): void
    {
        $csv = tempnam(sys_get_temp_dir(), 'rates');
        file_put_contents($csv, "WhatsApp rate card\nMarket,Currency,Marketing,Utility,Authentication,Authentication-International\n"
            ."United Arab Emirates,USD,0.0600,0.0160,0.0160,0.0510\nIndia,USD,0.0120,0.0015,0.0015,0.0304\nOther,USD,0.0610,0.0080,0.0080,\n");

        $this->assertSame(0, Artisan::call('engage:meta-rates:import', ['file' => $csv, '--effective' => now()->addMonth()->toDateString()]));
        $this->assertSame('2026-10-01', $this->audience($this->template('big_sale'))['cost_estimate']['rates_effective_from']); // not yet

        $this->travel(32)->days();
        $estimate = $this->audience($this->template('big_sale'))['cost_estimate'];
        $this->assertSame(0.0600, collect($estimate['by_country'])->firstWhere('country', 'AE')['rate']);
        $this->assertSame('Other', collect($estimate['by_country'])->firstWhere('country', 'SA')['market']); // not in this file: priced as Other
        $this->assertSame(0.0610, collect($estimate['by_country'])->firstWhere('country', 'SA')['rate']);

        $this->assertSame(1, Artisan::call('engage:meta-rates:import', ['file' => $csv]));
        $this->assertSame(2, DB::table('meta_rates')->distinct()->count('effective_from'));
    }
}
