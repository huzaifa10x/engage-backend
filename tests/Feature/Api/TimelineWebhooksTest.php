<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Developer\Jobs\DeliverWebhook;
use App\Domain\Developer\Models\WebhookDelivery;
use App\Domain\Developer\Services\TimelineWebhooks;
use App\Domain\Developer\Services\UrlGuard;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timeline\Timeline;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Batch 6, item 5: outgoing webhooks are read from the contact timeline, signed, and retried for a day. */
final class TimelineWebhooksTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant(['timezone' => 'Asia/Dubai']);
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->connectNumber($this->tenant);
        $this->app->bind(UrlGuard::class, fn () => new class extends UrlGuard
        {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        Http::fake([
            'graph.facebook.com/*' => fn () => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '0']], 'messages' => [['id' => 'wamid.T'.bin2hex(random_bytes(6))]]]),
            'hooks.customer.example/*' => Http::response(['ok' => true]),
            'down.customer.example/*' => Http::response('boom', 503),
        ]);
        $this->actingAsMember($this->owner);
    }

    /** @return list<array<string, mixed>> the bodies received for $event, each with its signature checked */
    private function received(string $secret, string $event): array
    {
        $bodies = [];
        foreach (Http::recorded(fn (Request $r) => str_contains($r->url(), 'hooks.customer.example') && $r->header('X-Engage-Event')[0] === $event) as [$request]) {
            [$t, $v1] = array_map(fn (string $p) => explode('=', $p, 2)[1], explode(',', $request->header('X-Engage-Signature')[0]));
            $this->assertTrue(hash_equals(hash_hmac('sha256', $t.'.'.$request->body(), $secret), $v1), "bad signature on {$event}");
            $bodies[] = json_decode($request->body(), true);
        }

        return $bodies;
    }

    public function test_a_receiver_gets_every_chosen_event_from_the_timeline_signed_with_its_enquiry_actor_and_time_zone(): void
    {
        $events = ['contact.created', 'contact.tag_added', 'contact.tag_removed', 'contact.consent_changed', 'contact.opted_in',
            'enquiry.created', 'enquiry.stage_changed', 'conversation.assigned', 'conversation.closed'];
        $this->assertEmpty(array_diff($events, array_column($this->getJson('/api/v1/developer')->assertOk()->json('data.events'), 'key')));
        $secret = (string) $this->postJson('/api/v1/developer/webhooks', ['url' => 'https://hooks.customer.example/engage', 'events' => $events])->assertCreated()->json('data.secret');

        // A customer writes in: a contact and an enquiry.
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['id' => 'wamid.W1'], '971501234567', 'AE.1', 'Sara')))->assertOk();
        $contact = $this->tenantContext()->run($this->tenant, fn () => Contact::query()->sole());
        $conversation = $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->sole());

        // The team works the lead.
        $this->patchJson("/api/v1/contacts/{$contact->id}", ['tags' => ['SUV']])->assertOk();
        $this->patchJson("/api/v1/contacts/{$contact->id}", ['tags' => []])->assertOk();
        $this->postJson("/api/v1/contacts/{$contact->id}/consent", ['state' => 'opted_in', 'note' => 'Said yes on the phone'])->assertOk();
        $this->postJson('/api/v1/crm/stages/suggested')->assertOk();
        $qualified = (string) $this->tenantContext()->run($this->tenant, fn () => DB::table('crm_stages')->where('name', 'Qualified')->value('id'));
        $version = (int) $this->tenantContext()->run($this->tenant, fn () => DB::table('enquiries')->where('contact_id', $contact->id)->value('stage_version'));
        $this->postJson("/api/v1/contacts/{$contact->id}/stage", ['stage_id' => $qualified, 'version' => $version])->assertOk();
        $this->patchJson("/api/v1/conversations/{$conversation->id}", ['assigned_membership_id' => $this->owner->id])->assertOk();
        $this->patchJson("/api/v1/conversations/{$conversation->id}", ['status' => 'closed'])->assertOk();

        $enquiryId = (string) $this->tenantContext()->run($this->tenant, fn () => DB::table('enquiries')->where('contact_id', $contact->id)->value('id'));
        foreach ($events as $event) {
            $got = $this->received($secret, $event);
            $this->assertCount(1, $got, "{$event} arrives once");
            $body = $got[0];
            // A contact is created a moment before its first enquiry starts.
            $this->assertSame([$event, 'Asia/Dubai', $event === 'contact.created' ? null : $enquiryId], [$body['event'], $body['timezone'], $body['enquiry_id']], $event);
            $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $body['occurred_at'], $event);
            $this->assertContains($body['actor'], ['customer', 'agent', 'system'], $event);
            $this->assertNotEmpty($body['timeline_event_id'], $event);
        }

        $by = fn (string $event) => $this->received($secret, $event)[0];
        $this->assertSame(['system', '+971501234567'], [$by('contact.created')['actor'], $by('contact.created')['data']['phone']]);
        $this->assertSame(['agent', $this->owner->user->name, 'SUV'], [$by('contact.tag_added')['actor'], $by('contact.tag_added')['actor_name'], $by('contact.tag_added')['data']['tag']]);
        $this->assertSame(['opted_in', 'agent'], [$by('contact.consent_changed')['data']['action'], $by('contact.consent_changed')['actor']]);
        $this->assertSame(['Qualified', 'open'], [$by('enquiry.stage_changed')['data']['to'], $by('enquiry.stage_changed')['data']['to_group']]);
        $this->assertSame($contact->id, $by('enquiry.stage_changed')['data']['contact']['id']);
        $this->assertSame([$this->owner->id, 'closed'], [$by('conversation.assigned')['data']['assigned_to'], $by('conversation.closed')['data']['status']]);
    }

    public function test_a_failing_receiver_is_retried_for_about_a_day_and_the_log_shows_every_attempt(): void
    {
        $this->assertGreaterThanOrEqual(22 * 3600, array_sum(DeliverWebhook::BACKOFF));
        $this->assertLessThanOrEqual(24 * 3600, array_sum(DeliverWebhook::BACKOFF));

        $this->postJson('/api/v1/developer/webhooks', ['url' => 'https://down.customer.example/hook', 'events' => ['contact.created']])->assertCreated();
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['id' => 'wamid.W2'], '971501234568', 'AE.2', 'Omar')))->assertOk();

        $this->assertCount(count(DeliverWebhook::BACKOFF) + 1, Http::recorded(fn (Request $r) => str_contains($r->url(), 'down.customer.example')));
        $log = $this->getJson('/api/v1/developer/deliveries')->assertOk()->json('data.0');
        $this->assertSame(['contact.created', 'failed', 503, count(DeliverWebhook::BACKOFF) + 1], [$log['event'], $log['status'], $log['response_status'], $log['attempts']]);
        $this->assertCount(count(DeliverWebhook::BACKOFF) + 1, $log['attempt_log']);
        $this->assertSame([503, 'boom'], [$log['attempt_log'][0]['status'], $log['attempt_log'][0]['error']]);
        // Resend works after the receiver is fixed.
        $this->postJson("/api/v1/developer/deliveries/{$log['id']}/resend")->assertStatus(202);
    }

    public function test_rebuilding_history_sends_nothing(): void
    {
        $this->postJson('/api/v1/developer/webhooks', ['url' => 'https://hooks.customer.example/engage', 'events' => ['enquiry.created']])->assertCreated();
        $contact = $this->tenantContext()->run($this->tenant, fn () => Contact::query()->create(['wa_id' => '971501234569']));
        TimelineWebhooks::paused(fn () => $this->tenantContext()->run($this->tenant, fn () => Timeline::record($contact->id, 'enquiry.started', Timeline::SYSTEM)));
        $this->assertSame(0, $this->tenantContext()->bypass(fn () => WebhookDelivery::query()->where('event', 'enquiry.created')->count()));
    }
}
