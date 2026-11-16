<?php

declare(strict_types=1);

namespace Tests\Feature\Conversions;

use App\Domain\Commerce\Models\CommerceOrder;
use App\Domain\Conversions\ConversionsAccess;
use App\Domain\Conversions\Models\MetaDataset;
use App\Domain\Conversions\Services\ConversionRules;
use App\Domain\Conversions\Services\ConversionTriggers;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * Conversions to Meta, 3a: which moments become conversion events, exactly once, and which are
 * skipped with a reason. Nothing is sent here (that is the sender's job).
 */
final class ConversionRecordingTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->connectNumber($this->tenant);
        Http::preventStrayRequests();
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.OUT'.Str::random(8)]]])]);
        $this->tenantContext()->run($this->tenant, function () {
            ConversionsAccess::set($this->tenant, true);
            $waba = WabaAccount::query()->firstOrFail();
            $waba->forceFill(['events_permission' => true])->save();
            MetaDataset::query()->create(['waba_account_id' => $waba->id, 'waba_id' => $waba->waba_id, 'dataset_id' => '1503923451234567', 'enabled' => true]);
            app(ConversionRules::class)->leadOnTag('Booked');
            app(ConversionRules::class)->purchasesFromOrders();
        });
        $this->actingAsMember($this->owner);
    }

    private function fromAd(string $waId = '971501234567', ?string $clid = 'ARAkLkA8rmlFeiCktEJQ'): string
    {
        $referral = array_filter(['source_id' => '120210000000000001', 'source_type' => 'ad', 'source_url' => 'https://fb.me/x', 'headline' => 'Free class', 'ctwa_clid' => $clid]);
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['referral' => $referral], $waId, 'AE.'.$waId)))->assertOk();

        return (string) $this->tenantContext()->bypass(fn () => Contact::query()->where('wa_id', $waId)->value('id'));
    }

    /** @return list<array<string, mixed>> */
    private function events(): array
    {
        return $this->tenantContext()->bypass(fn () => DB::table('conversion_events')->orderBy('created_at')->get()->map(fn ($e) => (array) $e)->all());
    }

    private function tag(string $contactId, array $tags): void
    {
        $this->patchJson("/api/v1/contacts/{$contactId}", ['tags' => $tags])->assertOk();
    }

    public function test_a_lead_tag_on_an_ad_chat_is_queued_once_whatever_happens_to_the_tag_later(): void
    {
        $contact = $this->fromAd();
        $this->tag($contact, ['booked']);
        $this->tag($contact, []);            // removed
        $this->tag($contact, ['BOOKED']);    // and added again
        $this->tag($contact, ['booked', 'vip']);

        $events = $this->events();
        $this->assertCount(1, $events);
        $this->assertSame(['LeadSubmitted', 'queued', '102290129340398', false], [$events[0]['event_name'], $events[0]['status'], $events[0]['waba_id'], $events[0]['is_test']]);
        $this->assertNotNull($events[0]['ad_click_id']);
        $this->assertSame('ARAkLkA8rmlFeiCktEJQ', $this->tenantContext()->bypass(fn () => DB::table('ad_clicks')->where('id', $events[0]['ad_click_id'])->value('ctwa_clid')));
        $this->assertNull($events[0]['value']);
    }

    public function test_a_paid_order_linked_to_the_chat_is_one_purchase_with_its_value_and_editing_it_adds_nothing(): void
    {
        $contact = $this->fromAd();
        $order = $this->tenantContext()->run($this->tenant, fn () => CommerceOrder::query()->create([
            'integration_id' => (string) Str::uuid(), 'store_name' => 'Nova Store', 'external_id' => '1001', 'number' => '1001', 'status' => 'pending',
            'currency' => 'AED', 'total' => '349.50', 'paid' => false, 'placed_at' => now(), 'contact_id' => $contact,
            'conversation_id' => Conversation::query()->where('contact_id', $contact)->value('id'), 'match' => CommerceOrder::LINK,
        ]));
        $changed = fn () => $this->tenantContext()->run($this->tenant, fn () => ConversionTriggers::orderChanged($order->fresh()));

        $changed(); // not paid yet: nothing
        $this->assertSame([], $this->events());

        $this->tenantContext()->run($this->tenant, fn () => $order->forceFill(['status' => 'processing', 'paid' => true, 'paid_at' => now()])->save());
        $changed();
        $this->tenantContext()->run($this->tenant, fn () => $order->forceFill(['status' => 'completed', 'total' => '399.00'])->save());
        $changed(); // edited: nothing new

        $events = $this->events();
        $this->assertCount(1, $events);
        $this->assertSame(['Purchase', 'queued', '349.50', 'AED', $order->id], [$events[0]['event_name'], $events[0]['status'], $events[0]['value'], $events[0]['currency'], $events[0]['order_id']]);
    }

    public function test_conversions_that_cannot_be_reported_are_skipped_with_the_reason(): void
    {
        // No click ID: a chat that did not start from an ad.
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue([], '971502222222', 'AE.971502222222')))->assertOk();
        $organic = (string) $this->tenantContext()->bypass(fn () => Contact::query()->where('wa_id', '971502222222')->value('id'));
        $this->tag($organic, ['booked']);

        // The token lost the permission.
        $this->tenantContext()->run($this->tenant, fn () => WabaAccount::query()->firstOrFail()->forceFill(['events_permission' => false])->save());
        $this->tag($this->fromAd('971503333333'), ['booked']);

        // Reporting switched off for the account.
        $this->tenantContext()->run($this->tenant, fn () => MetaDataset::query()->update(['enabled' => false]));
        $this->tag($this->fromAd('971504444444'), ['booked']);

        $this->assertSame([
            ['skipped', 'The chat did not start from a Meta ad (no click ID), so Meta cannot match it.'],
            ['skipped', 'The WhatsApp account has not given 10X Engage permission to send conversion events. Reconnect it.'],
            ['skipped', 'Conversion reporting is off for this WhatsApp account.'],
        ], array_map(fn ($e) => [$e['status'], $e['error_reason']], $this->events()));
    }

    public function test_a_workspace_without_the_flag_records_nothing(): void
    {
        $this->tenantContext()->run($this->tenant, fn () => ConversionsAccess::set($this->tenant, false));
        $this->tag($this->fromAd(), ['booked']);

        $this->assertSame([], $this->events());
    }

    public function test_a_real_flow_run_reaching_the_step_is_one_lead_and_test_runs_record_nothing(): void
    {
        $flowId = (string) $this->postJson('/api/v1/chatbots', ['name' => 'Booking', 'phone_number_id' => $this->tenantContext()->bypass(fn () => DB::table('phone_numbers')->value('id')), 'template' => 'blank'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/chatbots/{$flowId}", ['draft' => ['nodes' => [
            ['id' => 'start', 'type' => 'start', 'title' => 'Start', 'x' => 0, 'y' => 0, 'config' => ['any_message' => true], 'outputs' => [['id' => 'next', 'to' => 'booked']]],
            ['id' => 'booked', 'type' => 'message', 'title' => 'Booked', 'x' => 0, 'y' => 0, 'config' => ['text' => 'You are booked'], 'outputs' => [['id' => 'next', 'to' => 'team']]],
            ['id' => 'team', 'type' => 'handoff', 'title' => 'Team', 'x' => 0, 'y' => 0, 'config' => ['text' => 'A person will reply.'], 'outputs' => []],
        ]]])->assertOk();
        $this->tenantContext()->run($this->tenant, fn () => app(ConversionRules::class)->leadOnFlowStep($flowId, 'booked'));

        // A test run (the builder's Test tab) reaches the step: nothing is recorded.
        $this->postJson("/api/v1/chatbots/{$flowId}/test", [])->assertOk()->assertJsonPath('data.is_test', true);
        $this->assertSame([], $this->events());

        // The real flow, live on the number: the customer's message from the ad runs it to the step.
        $this->postJson("/api/v1/chatbots/{$flowId}/publish")->assertOk();
        $this->postJson("/api/v1/chatbots/{$flowId}/activate", ['active' => true])->assertOk();
        $contact = $this->fromAd();
        $this->assertSame(1, $this->tenantContext()->bypass(fn () => DB::table('chatbot_events')->where('node_id', 'booked')->where('kind', 'enter')->count()), 'the flow reached the step');

        // The flow running again in the same chat (the person writes again) adds nothing.
        $conversation = (string) $this->tenantContext()->bypass(fn () => Conversation::query()->where('contact_id', $contact)->value('id'));
        $this->tenantContext()->run($this->tenant, fn () => ConversionTriggers::flowStepReached($conversation, $contact, $flowId, 'booked'));

        $events = $this->events();
        $this->assertCount(1, $events);
        $this->assertSame(['LeadSubmitted', 'queued', $conversation], [$events[0]['event_name'], $events[0]['status'], $events[0]['conversation_id']]);
    }
}
