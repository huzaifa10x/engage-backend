<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Access\SystemRole;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timeline\Enquiry;
use App\Domain\Timeline\TimelineEvent;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Batch 6a, item 0: the contact timeline and enquiries. */
final class TimelineAndEnquiriesTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    private string $storeId = '';

    private string $secret = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        config(['app.url' => 'https://api.engage.test']);
        $this->tenant = $this->createTenant(['name' => 'Fast Wheels Car Rental', 'timezone' => 'Asia/Dubai']);
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
        Http::fake(['graph.facebook.com/*' => fn (Request $r) => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '0']], 'messages' => [['id' => 'wamid.T'.bin2hex(random_bytes(8))]]])]);
        $this->actingAsMember($this->owner);
    }

    /** @param array<string, mixed> $overrides */
    private function customer(array $overrides, string $waId = '971501230001'): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue($overrides, $waId, null, 'Omar Khalid')))->assertOk();
    }

    private function contact(string $waId = '971501230001'): Contact
    {
        return $this->tenantContext()->run($this->tenant, fn () => Contact::query()->where('wa_id', $waId)->firstOrFail());
    }

    private function conversationId(string $waId = '971501230001'): string
    {
        return (string) $this->tenantContext()->run($this->tenant, fn () => DB::table('conversations')->where('contact_id', $this->contact($waId)->id)->value('id'));
    }

    /** @param array<string, mixed> $payload */
    private function plugin(array $payload): TestResponse
    {
        $body = (string) json_encode($payload);
        $ts = time();

        return $this->call('POST', "/api/integrations/woocommerce/{$this->storeId}/plugin/events", [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_ENGAGE_TIMESTAMP' => (string) $ts,
            'HTTP_X_ENGAGE_SIGNATURE' => base64_encode(hash_hmac('sha256', $ts.'.'.$body, $this->secret, true)),
        ], $body);
    }

    private function paidOrder(string $id, string $total): void
    {
        if ($this->storeId === '') {
            $key = (string) $this->postJson('/api/v1/integrations/woocommerce/plugin-key')->assertCreated()->json('data.key');
            $r = $this->postJson('/api/integrations/woocommerce/plugin/connect', ['key' => $key, 'site_url' => 'https://fastwheels.example', 'name' => 'Fast Wheels'])->assertCreated();
            $this->storeId = (string) $r->json('data.store_id');
            $this->secret = (string) $r->json('data.secret');
        }
        $this->plugin(['events' => [['event' => 'paid', 'occurred_at' => now()->toIso8601String(), 'order' => [
            'id' => $id, 'number' => $id, 'status' => 'processing', 'currency' => 'AED', 'total' => $total, 'total_refunded' => '0.00',
            'date_created' => now()->toIso8601String(), 'date_paid' => now()->toIso8601String(), 'date_modified' => now()->toIso8601String(),
            'billing' => ['first_name' => 'Omar', 'last_name' => 'Khalid', 'phone' => '050 123 0001', 'country' => 'AE'], 'line_items' => [],
        ]]]])->assertSuccessful();
    }

    private function stage(string $name): void
    {
        $id = $this->tenantContext()->run($this->tenant, fn () => DB::table('crm_stages')->where('name', $name)->value('id'));
        $version = collect($this->getJson("/api/v1/contacts/{$this->contact()->id}/enquiries")->json('data'))->firstWhere('current', true)['stage_version'] ?? 0;
        $this->postJson("/api/v1/contacts/{$this->contact()->id}/stage", ['stage_id' => $id, 'version' => $version])->assertOk();
    }

    public function test_a_contact_who_rents_twice_has_two_enquiries_each_with_its_own_source_stage_and_payment(): void
    {
        $this->postJson('/api/v1/crm/stages/suggested')->assertOk();

        // March: from a Click-to-WhatsApp ad (click ID present) → verified ad source.
        $this->customer(['text' => ['body' => 'Hi, I need an SUV for 3 days'], 'referral' => [
            'source_url' => 'https://fb.me/abc', 'source_id' => '120208000000000777', 'source_type' => 'ad', 'headline' => 'SUVs from AED 199/day', 'ctwa_clid' => 'ARAkLm0001',
        ]]);
        $this->postJson("/api/v1/conversations/{$this->conversationId()}/messages", ['type' => 'text', 'body' => 'Sure, which dates?'])->assertSuccessful();
        $this->stage('Qualified');
        $this->paidOrder('5001', '597.00');

        // June: the same customer comes back after the quiet period, from a QR code in the shop window.
        $this->travel(90)->days();
        $link = $this->postJson('/api/v1/wa-links', ['phone_number_id' => $this->number->id, 'name' => 'Shop window', 'message' => 'Hi'])->assertCreated()->json('data');
        $this->customer(['text' => ['body' => $link['prefilled_text']]]);
        // Paid in full (a verified payment), so the lead can be marked Won (item 4).
        $this->paidOrder('5002', '890.00');
        $this->stage('Won');

        $enquiries = collect($this->getJson("/api/v1/contacts/{$this->contact()->id}/enquiries")->assertOk()->json('data'))->keyBy('number');
        $this->assertCount(2, $enquiries);
        $this->assertSame(['ad', 'verified', 'Ad: “SUVs from AED 199/day”', 'first_message'],
            [$enquiries[1]['source'], $enquiries[1]['source_confidence'], $enquiries[1]['source_label'], $enquiries[1]['start_reason']]);
        $this->assertSame(['link', 'verified', 'Link: Shop window', 'quiet_period'],
            [$enquiries[2]['source'], $enquiries[2]['source_confidence'], $enquiries[2]['source_label'], $enquiries[2]['start_reason']]);
        $this->assertSame('Qualified', $enquiries[1]['stage']['name']);
        $this->assertSame('Won', $enquiries[2]['stage']['name']);
        $this->assertSame(['5001'], array_column($enquiries[1]['payments'], 'number'));
        $this->assertSame(['5002'], array_column($enquiries[2]['payments'], 'number'));
        $this->assertTrue($enquiries[2]['current']);
        // The click ID itself is never stored on the enquiry.
        $this->assertStringNotContainsString('ARAkLm0001', (string) json_encode($this->tenantContext()->run($this->tenant, fn () => Enquiry::query()->get()->toArray())));

        // The history, filtered to one enquiry and by type.
        $first = $this->getJson("/api/v1/contacts/{$this->contact()->id}/timeline?enquiry_id={$enquiries[1]['id']}")->assertOk()->json('data');
        // The agent's reply assigns the chat to them; the store tags its customers.
        $this->assertEqualsCanonicalizing(['enquiry.started', 'message.received', 'message.sent', 'conversation.assigned', 'stage.changed', 'tag.added', 'payment.paid'], array_values(array_unique(array_column($first, 'type'))));
        $payments = $this->getJson("/api/v1/contacts/{$this->contact()->id}/timeline?group=payments")->json('data');
        $this->assertSame([2, 1], array_column($payments, 'enquiry_number'));
    }

    public function test_every_event_of_a_flow_is_on_the_timeline_with_who_caused_it(): void
    {
        $id = $this->postJson('/api/v1/chatbots', ['name' => 'Car rental', 'phone_number_id' => $this->number->id, 'template' => 'blank'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/chatbots/{$id}", ['draft' => ['nodes' => [
            ['id' => 'start', 'type' => 'start', 'title' => 'Start', 'config' => ['any_message' => true], 'outputs' => [['id' => 'next', 'to' => 'class']]],
            ['id' => 'class', 'type' => 'buttons', 'title' => 'Vehicle class', 'config' => ['text' => 'Which class?', 'buttons' => [['id' => 'suv', 'title' => 'SUV'], ['id' => 'economy', 'title' => 'Economy']]],
                'outputs' => [['id' => 'suv', 'to' => 'tag'], ['id' => 'economy', 'to' => 'tag']]],
            ['id' => 'tag', 'type' => 'tag', 'title' => 'Tag', 'config' => ['add' => ['SUV lead'], 'remove' => [], 'assign' => 'none'], 'outputs' => [['id' => 'next', 'to' => 'consent']]],
            ['id' => 'consent', 'type' => 'consent', 'title' => 'Consent', 'config' => ['text' => 'Offers on WhatsApp?', 'yes_label' => 'Yes', 'no_label' => 'No'],
                'outputs' => [['id' => 'yes', 'to' => 'end'], ['id' => 'no', 'to' => 'end']]],
            ['id' => 'end', 'type' => 'handoff', 'title' => 'Team', 'config' => ['text' => 'An agent will call you.'], 'outputs' => []],
        ]]])->assertOk();
        $this->postJson("/api/v1/chatbots/{$id}/publish")->assertOk();
        $this->postJson("/api/v1/chatbots/{$id}/activate", ['active' => true])->assertOk();

        $this->customer(['text' => ['body' => 'Hi']]);
        $this->customer(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'bot:class:suv', 'title' => 'SUV']]]);
        $this->customer(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'bot:consent:yes', 'title' => 'Yes']]]);
        $agent = $this->addMember($this->tenant, SystemRole::Admin);
        $this->patchJson("/api/v1/conversations/{$this->conversationId()}", ['assigned_membership_id' => $agent->id])->assertOk();

        $events = collect($this->getJson("/api/v1/contacts/{$this->contact()->id}/timeline?per_page=200")->assertOk()->json('data'));
        $actor = fn (string $type) => $events->where('type', $type)->pluck('actor')->unique()->values()->all();
        $this->assertSame(['customer'], $actor('message.received'));
        $this->assertSame(['customer'], $actor('enquiry.started'));
        $this->assertSame(['system'], $actor('message.sent'));
        $this->assertSame(['system'], $actor('flow.step'));
        $this->assertSame(['customer'], $actor('flow.answer'));
        $this->assertSame('SUV lead', $events->firstWhere('type', 'tag.added')['data']['tag']);
        $this->assertSame(['customer'], $actor('consent.changed'));
        $this->assertSame('Flow step', $events->firstWhere('type', 'consent.changed')['data']['source_label']);
        $assigned = $events->firstWhere('type', 'conversation.assigned');
        $this->assertSame(['agent', $this->owner->user->name], [$assigned['actor'], $assigned['actor_name']]);
        // All of it belongs to the one enquiry, whose source is Direct (unknown): nothing proved where it came from.
        $this->assertCount(1, $events->pluck('enquiry_id')->filter()->unique());
        $enquiry = $this->getJson("/api/v1/contacts/{$this->contact()->id}/enquiries")->json('data.0');
        $this->assertSame(['direct', 'unknown', $agent->id], [$enquiry['source'], $enquiry['source_confidence'], $enquiry['owner']['id']]);
    }

    public function test_quiet_period_new_ad_clicks_partial_sources_and_a_new_enquiry_by_hand(): void
    {
        $this->putJson('/api/v1/tenant/enquiry-settings', ['quiet_days' => 7])->assertOk()->assertJsonPath('data.quiet_days', 7);
        $this->customer(['text' => ['body' => 'Hi']]);
        $this->travel(6)->days();
        $this->customer(['text' => ['body' => 'Still there?']]);
        // A new ad click starts a new enquiry even inside the quiet period; an ad label without a click ID is only partial.
        $this->customer(['text' => ['body' => 'Saw your ad'], 'referral' => ['source_id' => '1202', 'source_type' => 'ad', 'source_url' => 'https://fb.me/x']]);
        $this->travel(8)->days();
        $this->customer(['text' => ['body' => 'Back again']]);

        $this->actingAsMember($this->owner);
        $this->postJson("/api/v1/contacts/{$this->contact()->id}/enquiries", ['note' => 'Called the office for a June booking'])->assertCreated()->assertJsonPath('data.number', 3);
        $list = collect($this->getJson("/api/v1/contacts/{$this->contact()->id}/enquiries")->json('data'))->keyBy('number');
        $this->assertSame(['first_message', 'quiet_period', 'manual'], [$list[1]['start_reason'], $list[2]['start_reason'], $list[3]['start_reason']]);
        $this->assertSame(['direct', 'unknown'], [$list[2]['source'], $list[2]['source_confidence']]);
        $this->assertSame('agent', $list[3]['started_by']);
        $started = collect($this->getJson("/api/v1/contacts/{$this->contact()->id}/timeline?group=enquiries")->json('data'))->firstWhere('data.number', 3);
        $this->assertSame(['agent', $this->owner->user->name], [$started['actor'], $started['actor_name']]);
    }

    public function test_a_referral_without_a_click_id_is_partial_and_a_new_click_starts_a_new_enquiry(): void
    {
        $this->customer(['text' => ['body' => 'Hi'], 'referral' => ['source_id' => '1202', 'source_type' => 'ad', 'source_url' => 'https://fb.me/x', 'headline' => 'Weekend deal']]);
        $this->customer(['text' => ['body' => 'Hi again'], 'referral' => ['source_id' => '1203', 'source_type' => 'ad', 'source_url' => 'https://fb.me/y', 'ctwa_clid' => 'ARAkNEW']]);
        $this->customer(['text' => ['body' => 'Same click, another message']]);
        $list = collect($this->getJson("/api/v1/contacts/{$this->contact()->id}/enquiries")->json('data'))->keyBy('number');
        $this->assertCount(2, $list);
        $this->assertSame(['ad', 'partial'], [$list[1]['source'], $list[1]['source_confidence']]);
        $this->assertSame(['ad', 'verified', 'ad_click'], [$list[2]['source'], $list[2]['source_confidence'], $list[2]['start_reason']]);
    }

    public function test_events_are_never_edited_and_one_workspace_cannot_read_anothers_timeline(): void
    {
        $this->customer(['text' => ['body' => 'Hi']]);
        $contact = $this->contact();

        // Never edited, not even directly in the database.
        $this->expectExceptionObject(new \LogicException('Timeline events are never edited; record a correction as a new event.'));
        try {
            $this->tenantContext()->run($this->tenant, fn () => DB::transaction(fn () => DB::table('timeline_events')->where('contact_id', $contact->id)->update(['type' => 'x'])));
            $this->fail('The database allowed an edit.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('timeline events are never edited', $e->getMessage());
        }

        // Another workspace: its people get 404, and its queries see nothing.
        $other = $this->createTenant(['name' => 'Other Rentals']);
        $this->subscribe($other, 'pro');
        $stranger = $this->addMember($other);
        $this->actingAsMember($stranger);
        $this->getJson("/api/v1/contacts/{$contact->id}/timeline")->assertNotFound();
        $this->getJson("/api/v1/contacts/{$contact->id}/enquiries")->assertNotFound();
        $this->assertSame(0, $this->tenantContext()->run($other, fn () => TimelineEvent::query()->count() + Enquiry::query()->count() + DB::table('timeline_events')->count()));
        $this->assertGreaterThan(0, $this->tenantContext()->run($this->tenant, fn () => TimelineEvent::query()->count()));

        $this->tenantContext()->run($this->tenant, fn () => TimelineEvent::query()->firstOrFail()->forceFill(['type' => 'x'])->save());
    }

    public function test_enquiries_are_built_from_earlier_messages_once(): void
    {
        $this->customer(['text' => ['body' => 'Hi']]);
        $this->travel(45)->days();
        $this->customer(['text' => ['body' => 'Hi again']]);
        // As before this release: no enquiries yet.
        $this->tenantContext()->run($this->tenant, function () {
            DB::table('contacts')->update(['current_enquiry_id' => null]);
            DB::table('enquiries')->delete();
        });

        $this->artisan('engage:enquiries:backfill')->assertSuccessful();
        $this->artisan('engage:enquiries:backfill')->assertSuccessful(); // again: nothing new
        $list = collect($this->getJson("/api/v1/contacts/{$this->contact()->id}/enquiries")->json('data'));
        $this->assertSame([2, 1], $list->pluck('number')->all());
        $this->assertSame(['backfill', 'backfill'], $list->pluck('start_reason')->all());
        $this->assertTrue($list->first()['current']);
    }
}
