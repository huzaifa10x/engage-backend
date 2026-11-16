<?php

declare(strict_types=1);

namespace Tests\Feature\Chatbots;

use App\Domain\Developer\Services\UrlGuard;
use App\Domain\Messaging\Models\Contact;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * A test run of a flow (the builder's Test tab) is never a real conversation. Every run is marked
 * is_test, live-integration tests included. The default dry run makes no outbound call at all and
 * writes nothing that Leads, reports, Runs or insights read: no session, no event, no message, no
 * conversation, no contact change, no consent change, no notification.
 */
final class ChatbotTestRunIsolationTest extends TestCase
{
    use InteractsWithWhatsapp;

    /** @var list<string> every outbound HTTP request, whatever the host */
    private array $outbound = [];

    private const TABLES = [
        'chatbot_sessions', 'chatbot_events', 'messages', 'conversations', 'contacts', 'consent_events',
        'contact_stage_events', 'member_notifications', 'growth_evidence', 'commerce_orders',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
        $this->app->bind(UrlGuard::class, fn () => new class extends UrlGuard
        {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        Http::preventStrayRequests();
        Http::fake(['*' => function (Request $r) {
            $this->outbound[] = $r->method().' '.$r->url();

            return Http::response(['ok' => true, 'lead' => ['id' => 'REAL-1']]);
        }]);
        $this->actingAsMember($this->owner);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return $this->tenantContext()->bypass(fn () => array_combine(self::TABLES, array_map(fn (string $t) => DB::table($t)->count(), self::TABLES)));
    }

    /** A flow touching everything a live run would change: messages, an API, a sheet, tags, a field, consent, a handover. */
    private function flow(): string
    {
        $id = (string) $this->postJson('/api/v1/chatbots', ['name' => 'Everything', 'phone_number_id' => $this->number->id, 'template' => 'blank'])->assertCreated()->json('data.id');
        $node = fn (string $nid, string $type, array $config, array $out = []) => ['id' => $nid, 'type' => $type, 'title' => ucfirst($nid), 'x' => 0, 'y' => 0, 'config' => $config,
            'outputs' => array_map(fn ($o, $to) => ['id' => $o, 'to' => $to], array_keys($out), $out)];
        $this->patchJson("/api/v1/chatbots/{$id}", ['draft' => ['nodes' => [
            $node('start', 'start', ['any_message' => true], ['next' => 'hello']),
            $node('hello', 'message', ['text' => 'Hi {{contact.first_name}}'], ['next' => 'day']),
            $node('day', 'question', ['text' => 'Which day?', 'validate' => 'future_date', 'save_to' => 'var:day'], ['next' => 'crm']),
            $node('crm', 'http', ['method' => 'POST', 'url' => 'https://api.example.com/leads', 'body' => '{"day":"{{var.day}}"}', 'sample_status' => 201, 'sample_body' => '{"lead":{"id":"SAMPLE-1"}}'], ['success' => 'sheet', 'error' => 'sheet']),
            $node('sheet', 'sheets', ['webapp_url' => 'https://script.google.com/macros/s/X/exec', 'sheet' => 'Leads', 'columns' => [['name' => 'Day', 'value' => '{{var.day}}']]], ['next' => 'tag']),
            $node('tag', 'tag', ['add' => ['Booked'], 'remove' => [], 'assign' => 'auto'], ['next' => 'optin']),
            $node('optin', 'optin', ['text' => 'Subscribed'], ['next' => 'team']),
            $node('team', 'handoff', ['text' => 'A person will reply.']),
        ]]])->assertOk();

        return $id;
    }

    public function test_the_default_dry_run_makes_zero_outbound_calls_and_changes_nothing(): void
    {
        $id = $this->flow();
        $contact = (string) $this->postJson('/api/v1/contacts', ['phone' => '+971501234567', 'name' => 'Sara'])->assertCreated()->json('data.id');
        $before = $this->counts();
        $snapshot = fn () => $this->tenantContext()->bypass(fn () => json_encode(Contact::query()->findOrFail($contact)->only(['tags', 'consent_state', 'custom_fields', 'stage_id', 'marketing_opted_out', 'updated_at'])));
        $contactBefore = $snapshot();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        $this->outbound = [];

        // A whole run, as the real customer, through every step.
        $first = $this->postJson("/api/v1/chatbots/{$id}/test", ['contact_id' => $contact])->assertOk()
            ->assertJsonPath('data.is_test', true)->assertJsonPath('data.integrations', 'mock');
        $this->postJson("/api/v1/chatbots/{$id}/test", ['test_id' => $first->json('data.test_id'), 'text' => '25/12'])->assertOk()
            ->assertJsonPath('data.is_test', true)->assertJsonPath('data.state.status', 'handed_off');

        $this->assertSame([], $this->outbound, 'no request left the app: no WhatsApp send, no API, no sheet');
        Queue::assertNothingPushed();
        Mail::assertNothingSent();
        Notification::assertNothingSent();
        $this->assertSame($before, $this->counts(), 'no session, event, message, conversation, contact, consent, stage, notification, lead evidence or order');
        $this->assertSame($contactBefore, $snapshot(), 'the real contact is untouched (tags, consent, fields, stage)');

        // Nothing shows in Runs or the flow's insights.
        $this->getJson("/api/v1/chatbots/runs?flow={$id}")->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, (int) ($this->getJson("/api/v1/chatbots/{$id}/analytics")->assertOk()->json('data.sessions.started') ?? -1));
    }

    public function test_an_owner_confirmed_live_test_is_marked_is_test_and_still_writes_nothing_real(): void
    {
        $id = $this->flow();
        $this->putJson('/api/v1/chatbots/workspace', ['test_endpoints' => ['https://api.example.com/']])->assertOk();
        $before = $this->counts();
        $this->outbound = [];

        $first = $this->postJson("/api/v1/chatbots/{$id}/test", ['integrations' => 'live', 'confirm_live' => true, 'live_reason' => 'Check the CRM sandbox'])->assertOk()
            ->assertJsonPath('data.is_test', true)->assertJsonPath('data.integrations', 'live');
        $this->postJson("/api/v1/chatbots/{$id}/test", ['test_id' => $first->json('data.test_id'), 'text' => '25/12', 'integrations' => 'live', 'confirm_live' => true, 'live_reason' => 'Check the CRM sandbox'])->assertOk()
            ->assertJsonPath('data.is_test', true);

        // Only the listed test endpoint was called; the sheet (not listed) stayed a dry run.
        $this->assertSame(['POST https://api.example.com/leads'], $this->outbound);
        $this->assertSame($before, $this->counts());
        $audit = $this->tenantContext()->bypass(fn () => DB::table('audit_log')->whereIn('action', ['chatbot.test_live_calls', 'chatbot.test_live_call'])->pluck('meta'));
        $this->assertNotEmpty($audit);
        foreach ($audit as $meta) {
            $this->assertTrue(json_decode((string) $meta, true)['is_test'] ?? false, 'every live-test audit entry is marked is_test');
        }
    }
}
