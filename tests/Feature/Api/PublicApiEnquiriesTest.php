<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Crm\Models\ContactStageEvent;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsChatbots;
use Tests\TestCase;

/** Batch 6, item 6: the public API with API keys: tags, enquiries and stages, the timeline, flows. */
final class PublicApiEnquiriesTest extends TestCase
{
    use BuildsChatbots;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootChatbots();
        $this->actingAsMember($this->owner);
        $this->postJson('/api/v1/crm/stages/suggested')->assertOk();
        $this->approvedTemplate('new_listings');
    }

    /** @param list<string>|string $scopes a preset (read_only, read_write) or a list of permissions */
    private function key(array|string $scopes = 'read_write', string $name = 'Booking system'): array
    {
        $this->actingAsMember($this->owner);
        if (is_string($scopes)) {
            $scopes = collect($this->getJson('/api/v1/developer')->assertOk()->json('data.presets'))->firstWhere('key', $scopes)['scopes'];
        }
        $created = $this->postJson('/api/v1/developer/api-keys', ['name' => $name, 'scopes' => $scopes])->assertCreated();

        return [(string) $created->json('data.key'), (string) $created->json('data.id')];
    }

    private function api(string $key): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json']);
    }

    private function stage(string $name): string
    {
        return (string) $this->inTenant(fn () => DB::table('crm_stages')->where('name', $name)->value('id'));
    }

    public function test_a_script_with_only_an_api_key_creates_tags_messages_and_moves_the_stage_and_every_change_is_audited_under_the_key(): void
    {
        [$key] = $this->key();

        $contact = $this->api($key)->postJson('/api/public/v1/contacts', ['phone' => '+971501112233', 'name' => 'Omar', 'opted_in' => true])->assertCreated()->json('data');
        $this->api($key)->postJson("/api/public/v1/contacts/{$contact['id']}/tags", ['tags' => ['SUV lead', 'Weekend']])->assertOk()->assertJsonPath('data.tags', ['SUV lead', 'Weekend']);
        $this->api($key)->deleteJson("/api/public/v1/contacts/{$contact['id']}/tags", ['tags' => ['weekend']])->assertOk()->assertJsonPath('data.tags', ['SUV lead']);
        $this->api($key)->postJson('/api/public/v1/messages', ['contact_id' => $contact['id'], 'type' => 'template', 'template' => ['name' => 'new_listings', 'language' => 'en']])->assertStatus(202);
        $this->assertSame('new_listings', $this->sent[array_key_last($this->sent)]['template']['name']);

        // The contact's enquiry (started when it was made) moves to Booked.
        $stages = collect($this->api($key)->getJson('/api/public/v1/stages')->assertOk()->json('data'));
        $this->assertSame(['open', 'won', 'lost'], $stages->pluck('group')->unique()->values()->all());
        // A contact made through the API has no enquiry until one is started (or they write in).
        $this->assertSame([], $this->api($key)->getJson("/api/public/v1/enquiries?contact_id={$contact['id']}")->assertOk()->json('data'));
        $enquiry = $this->api($key)->postJson('/api/public/v1/enquiries', ['contact_id' => $contact['id']])->assertCreated()->json('data');
        $this->assertSame($enquiry['id'], $this->api($key)->postJson('/api/public/v1/enquiries', ['contact_id' => $contact['id']])->assertOk()->json('data.id'), 'the current one, not a second');
        $this->assertNull($enquiry['stage']);
        $r = $this->api($key)->postJson("/api/public/v1/enquiries/{$enquiry['id']}/stage", ['stage_id' => $this->stage('Booked'), 'version' => $enquiry['version']]);
        $this->assertSame(200, $r->status(), (string) $r->getContent());
        $moved = $r->json('data');
        $this->assertSame(['Booked', $enquiry['version'] + 1], [$moved['stage']['name'], $moved['version']]);
        $this->assertSame('api', $this->inTenant(fn () => ContactStageEvent::query()->sole()->source));

        // Every change is in the Audit log under the key's name.
        $logged = $this->inTenant(fn () => AuditLog::query()->where('action', 'api.request')->orderBy('created_at')->get());
        $this->assertSame(
            ['POST /contacts', 'POST /contacts/{id}/tags', 'DELETE /contacts/{id}/tags', 'POST /messages', 'POST /enquiries', 'POST /enquiries', 'POST /enquiries/{id}/stage'],
            $logged->map(fn (AuditLog $l) => $l->meta['method'].' '.$l->meta['endpoint'])->all(),
        );
        $this->assertSame(['api', 'Booking system'], [$logged->first()->actor_type, $logged->first()->meta['api_key']]);
        $stageAudit = $this->inTenant(fn () => AuditLog::query()->where('action', 'enquiry.stage_changed')->sole());
        $this->assertSame(['api', 'Booking system'], [$stageAudit->actor_type, $stageAudit->meta['api_key']]);

        // The timeline shows what happened, newest first, in UTC.
        $events = collect($this->api($key)->getJson("/api/public/v1/timeline?contact_id={$contact['id']}")->assertOk()->json('data'));
        $this->assertContains('stage.changed', $events->pluck('type'));
        $this->assertContains('tag.added', $events->pluck('type'));
        $this->assertMatchesRegularExpression('/Z$/', $events->first()['occurred_at']);
        $this->assertCount(1, $this->api($key)->getJson("/api/public/v1/timeline?enquiry_id={$enquiry['id']}&type=stage.changed")->assertOk()->json('data'));
        $this->api($key)->getJson('/api/public/v1/timeline')->assertUnprocessable();
    }

    public function test_two_systems_cannot_overwrite_each_others_stage_and_won_needs_proof_or_the_approve_won_permission(): void
    {
        [$key] = $this->key();
        $this->says('Hi, a car for Friday?');
        $enquiry = $this->api($key)->getJson('/api/public/v1/enquiries')->assertOk()->json('data.0');

        $this->api($key)->postJson("/api/public/v1/enquiries/{$enquiry['id']}/stage", ['stage_id' => $this->stage('Qualified'), 'version' => $enquiry['version']])->assertOk();
        $this->api($key)->postJson("/api/public/v1/enquiries/{$enquiry['id']}/stage", ['stage_id' => $this->stage('Quote sent'), 'version' => $enquiry['version']])
            ->assertStatus(409)->assertJsonPath('error.code', 'conflict');

        $now = $this->api($key)->getJson("/api/public/v1/enquiries/{$enquiry['id']}")->assertOk()->json('data');
        $this->api($key)->postJson("/api/public/v1/enquiries/{$enquiry['id']}/stage", ['stage_id' => $this->stage('Won'), 'version' => $now['version'], 'reason' => 'INV-77'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $this->api($key)->postJson("/api/public/v1/enquiries/{$enquiry['id']}/stage", ['stage_id' => $this->stage('Lost'), 'version' => $now['version']])->assertUnprocessable();

        // A key given "approve Won" marks it with a reference.
        $presets = collect($this->getJson('/api/v1/developer')->json('data.presets'));
        $this->assertNotContains('stages:approve_won', $presets->flatMap(fn ($p) => $p['scopes']));
        [$approver] = $this->key(['enquiries:read', 'enquiries:write', 'stages:approve_won'], 'Accounts');
        $this->api($approver)->postJson("/api/public/v1/enquiries/{$enquiry['id']}/stage", ['stage_id' => $this->stage('Won'), 'version' => $now['version']])->assertUnprocessable(); // no reference
        $this->api($approver)->postJson("/api/public/v1/enquiries/{$enquiry['id']}/stage", ['stage_id' => $this->stage('Won'), 'version' => $now['version'], 'reason' => 'INV-77'])
            ->assertOk()->assertJsonPath('data.stage.group', 'won');
    }

    public function test_read_only_keys_cannot_write_and_a_revoked_key_stops_at_once(): void
    {
        [$read, $readId] = $this->key('read_only', 'Reports');
        $this->says('Hello');
        $contact = $this->api($read)->getJson('/api/public/v1/contacts')->assertOk()->json('data.0');
        $enquiry = $this->api($read)->getJson('/api/public/v1/enquiries')->assertOk()->json('data.0');
        $this->api($read)->postJson("/api/public/v1/contacts/{$contact['id']}/tags", ['tags' => ['x']])->assertForbidden()->assertJsonPath('error.code', 'insufficient_scope');
        $this->api($read)->postJson("/api/public/v1/enquiries/{$enquiry['id']}/stage", ['stage_id' => $this->stage('Booked'), 'version' => 0])->assertForbidden();
        $this->assertSame(0, $this->inTenant(fn () => AuditLog::query()->where('action', 'api.request')->count()), 'reads and refused writes are not logged as changes');

        $this->actingAsMember($this->owner);
        $this->deleteJson("/api/v1/developer/api-keys/{$readId}")->assertSuccessful();
        $this->api($read)->getJson('/api/public/v1/enquiries')->assertUnauthorized()->assertJsonPath('error.code', 'invalid_api_key');
    }

    public function test_a_key_starts_a_published_flow_for_a_contact(): void
    {
        $this->says('Hi'); // the customer wrote today, before the flow existed
        $flow = $this->liveFlow(['nodes' => [
            $this->node('start', 'start', ['any_message' => true], ['next' => 'hello']),
            $this->node('hello', 'message', ['text' => 'Your booking is confirmed. Anything else?'], ['next' => 'team']),
            $this->node('team', 'handoff', ['text' => 'A person will reply.']),
        ]]);
        [$key] = $this->key();

        $this->assertSame([$flow->id], array_column($this->api($key)->getJson('/api/public/v1/flows')->assertOk()->json('data'), 'id'));
        $contact = $this->contact();
        $this->api($key)->postJson("/api/public/v1/flows/{$flow->id}/start", ['contact_id' => $contact->id, 'vars' => ['booking' => 'BK-2041']])
            ->assertStatus(202)->assertJsonPath('data.result', 'started');
        $this->assertContains('Your booking is confirmed. Anything else?', $this->texts());
    }
}
