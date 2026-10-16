<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Domain\Messaging\Models\ConsentEvent;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * "Record opt-in" / "Opt out" in the inbox: the teammate's record is the consent; the customer
 * also gets the workspace's confirmation, but only inside a 24-hour window (never a free-form
 * message outside one) and only when Compliance has confirmations on.
 */
final class ConsentRecordingTest extends TestCase
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
        Http::fake(['graph.facebook.com/*/messages*' => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '971501234567']], 'messages' => [['id' => 'wamid.C'.bin2hex(random_bytes(4))]]])]);
        $this->actingAsMember($this->owner);
    }

    private function conversation(): Conversation
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(bsuid: null)))->assertOk();

        return $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->latest('created_at')->firstOrFail());
    }

    public function test_record_opt_in_records_it_and_confirms_to_the_customer_inside_the_window(): void
    {
        $conversation = $this->conversation();

        $this->postJson("/api/v1/contacts/{$conversation->contact_id}/consent", ['state' => 'opted_in', 'conversation_id' => $conversation->id])->assertOk()
            ->assertJsonPath('data.consent_state', 'opted_in')->assertJsonPath('meta.changed', true)->assertJsonPath('meta.confirmation.status', 'sent');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/messages') && ($r['text']['body'] ?? null) === 'You are subscribed. Reply STOP at any time to unsubscribe.');
        $this->tenantContext()->run($this->tenant, function (): void {
            $event = ConsentEvent::query()->sole();
            $this->assertSame(['opted_in', 'agent', $this->owner->id], [$event->action, $event->source, $event->membership_id]);
        });

        // Recording it again changes nothing and sends nothing.
        Http::fake(['*' => Http::response([], 500)]);
        $this->postJson("/api/v1/contacts/{$conversation->contact_id}/consent", ['state' => 'opted_in'])->assertOk()
            ->assertJsonPath('meta.changed', false)->assertJsonPath('meta.confirmation', null);
    }

    public function test_outside_the_window_the_consent_is_recorded_but_no_message_is_sent(): void
    {
        $conversation = $this->conversation();
        $this->travel(25)->hours();

        $this->postJson("/api/v1/contacts/{$conversation->contact_id}/consent", ['state' => 'opted_in'])->assertOk()
            ->assertJsonPath('data.consent_state', 'opted_in')->assertJsonPath('meta.confirmation.status', 'window_closed');
        Http::assertSentCount(0);
    }

    public function test_confirmations_off_in_compliance_or_notify_false_send_nothing(): void
    {
        $conversation = $this->conversation();
        $this->postJson("/api/v1/contacts/{$conversation->contact_id}/consent", ['state' => 'opted_in', 'notify' => false])->assertOk()
            ->assertJsonPath('meta.confirmation', null);

        $this->tenantContext()->bypass(fn () => $this->tenant->forceFill(['settings' => ['compliance' => ['confirm_opt_out' => false]]])->save());
        $this->postJson("/api/v1/contacts/{$conversation->contact_id}/consent", ['state' => 'opted_out'])->assertOk()
            ->assertJsonPath('data.consent_state', 'opted_out')->assertJsonPath('meta.confirmation.status', 'disabled');
        Http::assertSentCount(0);
    }

    public function test_a_teammate_recorded_opt_out_is_confirmed_once_and_then_blocks_business_messages(): void
    {
        $conversation = $this->conversation();
        $this->postJson("/api/v1/contacts/{$conversation->contact_id}/consent", ['state' => 'opted_out'])->assertOk()
            ->assertJsonPath('meta.confirmation.status', 'sent');
        Http::assertSent(fn (Request $r) => str_contains((string) ($r['text']['body'] ?? ''), 'unsubscribed'));

        $this->postJson("/api/v1/conversations/{$conversation->id}/messages", ['type' => 'template', 'template' => ['name' => 'hello_world', 'language' => 'en_US']])
            ->assertStatus(422)->assertJsonPath('error.code', 'contact_opted_out');
        $this->assertTrue($this->tenantContext()->run($this->tenant, fn () => Contact::query()->sole()->isOptedOut()));
    }

    public function test_a_contact_of_another_workspace_cannot_be_changed(): void
    {
        $conversation = $this->conversation();
        $other = $this->createTenant();
        $this->subscribe($other, 'pro');
        $this->actingAsMember($this->addMember($other));

        $this->postJson("/api/v1/contacts/{$conversation->contact_id}/consent", ['state' => 'opted_out'])->assertNotFound();
    }
}
