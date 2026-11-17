<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timeline\Enquiry;
use App\Domain\Timeline\Jobs\RecordTimeline;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/**
 * Batch 6a, item 0 review: bulk changes on the timeline, imported history as "Imported" enquiries,
 * message events linked to their message, and recording moved off the reply path into a queue.
 */
final class TimelineBulkImportedQueueTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant(['name' => 'Fast Wheels Car Rental']);
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
        Http::fake(['graph.facebook.com/*' => fn (Request $r) => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '0']], 'messages' => [['id' => 'wamid.T'.bin2hex(random_bytes(8))]]])]);
        $this->actingAsMember($this->owner);
    }

    private function customer(string $waId = '971501230001', string $text = 'Hi'): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['text' => ['body' => $text]], $waId, null, 'Omar Khalid')))->assertOk();
    }

    private function contactId(string $waId = '971501230001'): string
    {
        return (string) $this->tenantContext()->run($this->tenant, fn () => Contact::query()->where('wa_id', $waId)->value('id'));
    }

    /** @return Collection<int, object> */
    private function events(string $contactId, ?string $type = null): Collection
    {
        return $this->tenantContext()->run($this->tenant, fn () => DB::table('timeline_events')->where('contact_id', $contactId)
            ->when($type, fn ($q) => $q->where('type', $type))->orderBy('occurred_at')->get()
            ->map(function (object $e) {
                $e->data = json_decode((string) $e->data, true) ?? [];

                return $e;
            }));
    }

    private function ownerName(): string
    {
        return (string) $this->owner->user?->name;
    }

    public function test_recording_runs_in_the_background_after_the_change_and_never_for_a_rolled_back_change(): void
    {
        Queue::fake([RecordTimeline::class]);
        $this->customer();
        $contact = $this->contactId();

        // The message is in the inbox; nothing was written to the timeline on the way.
        $this->assertCount(0, $this->events($contact));
        $this->assertSame(0, $this->tenantContext()->bypass(fn () => DB::table('enquiries')->count()));
        Queue::assertPushedOn('maintenance', RecordTimeline::class);
        $jobs = Queue::pushed(RecordTimeline::class);
        $this->assertSame(['contact.created', 'message'], collect($jobs)->flatMap(fn (RecordTimeline $j) => array_column($j->entries, 'op'))->all(), 'in the order they happened');

        foreach ($jobs as $job) {
            $job->handle($this->tenantContext());
        }
        $received = $this->events($contact, 'message.received')->sole();
        $message = $this->tenantContext()->bypass(fn () => DB::table('messages')->where('contact_id', $contact)->first(['id', 'conversation_id']));
        $this->assertSame($message->id, $received->data['message_id'], '"Wrote a message" links to its message');
        $this->assertSame($message->conversation_id, $received->data['conversation_id']);
        $this->assertSame($message->id, $received->subject_id);
        $this->assertNotNull($received->enquiry_id, 'the enquiry was started in the background');

        // A change that is rolled back records nothing.
        $before = count(Queue::pushed(RecordTimeline::class));
        try {
            $this->tenantContext()->run($this->tenant, fn () => DB::transaction(function () use ($contact) {
                Contact::query()->findOrFail($contact)->forceFill(['tags' => ['VIP']])->save();
                throw new RuntimeException('cancelled');
            }));
        } catch (RuntimeException) {
        }
        $this->tenantContext()->run($this->tenant, fn () => DB::transaction(fn () => null)); // a later commit flushes nothing stale
        $this->assertCount($before, Queue::pushed(RecordTimeline::class));
    }

    public function test_bulk_tag_bulk_edit_import_and_deleting_a_tag_appear_on_each_contacts_timeline_with_who_did_it(): void
    {
        $this->customer('971501230001');
        $this->customer('971501230002');
        $ids = [$this->contactId('971501230001'), $this->contactId('971501230002')];
        $stages = collect($this->postJson('/api/v1/crm/stages/suggested')->assertOk()->json('data'))->pluck('id', 'name');

        $this->postJson('/api/v1/contacts/bulk-tag', ['contact_ids' => $ids, 'add' => ['VIP']])->assertOk();
        $this->postJson('/api/v1/contacts/bulk-edit', ['contact_ids' => $ids, 'confirm_count' => 2, 'stage' => ['id' => $stages['Qualified']],
            'consent' => ['state' => 'opted_in', 'note' => 'Signed the paper form']])->assertOk();
        $tag = $this->tenantContext()->run($this->tenant, fn () => DB::table('contact_tags')->where('name', 'VIP')->value('id'));
        $this->deleteJson("/api/v1/tags/{$tag}")->assertNoContent();
        $csv = "Name,Phone,Tags\nLayla,+971501239999,Gold\n";
        $this->post('/api/v1/contacts/import', ['file' => UploadedFile::fake()->createWithContent('c.csv', $csv), 'tags' => ['Expo']], ['Accept' => 'application/json'])->assertOk();

        foreach ($ids as $id) {
            $added = $this->events($id, 'tag.added')->firstWhere('data.tag', 'VIP');
            $this->assertSame(['bulk update', $this->ownerName()], [$added->data['bulk'], $added->data['bulk_by']], 'Tagged “VIP” in a bulk update by [name]');
            $this->assertSame('agent', $added->actor);
            $stage = $this->events($id, 'stage.changed')->sole();
            $this->assertSame(['Qualified', 'bulk update', $this->ownerName()], [$stage->data['to'], $stage->data['bulk'], $stage->data['bulk_by']]);
            $consent = $this->events($id, 'consent.changed')->sole();
            $this->assertSame('bulk update', $consent->data['bulk']);
            $removed = $this->events($id, 'tag.removed')->sole();
            $this->assertSame(['VIP', 'tag deleted', $this->ownerName()], [$removed->data['tag'], $removed->data['bulk'], $removed->data['bulk_by']]);
        }

        $layla = $this->contactId('971501239999');
        $tags = $this->events($layla, 'tag.added');
        $this->assertEqualsCanonicalizing(['Gold', 'Expo'], $tags->pluck('data.tag')->all(), 'tags a contact is created with are on the timeline');
        $this->assertSame(['import'], $tags->pluck('data.bulk')->unique()->values()->all());

        // A single contact edited by hand is not marked as bulk.
        $this->patchJson("/api/v1/contacts/{$ids[0]}", ['tags' => ['Solo']])->assertOk();
        $this->assertArrayNotHasKey('bulk', $this->events($ids[0], 'tag.added')->firstWhere('data.tag', 'Solo')->data);
    }

    public function test_imported_history_becomes_imported_enquiries_left_out_of_reports_and_a_live_message_starts_a_live_one(): void
    {
        // Two chats from the WhatsApp Business app history, as they are after an import.
        $this->customer('971501230001', 'Old question');
        $this->customer('971501230002', 'Old question');
        $this->tenantContext()->run($this->tenant, function () {
            DB::table('messages')->update(['origin' => 'history', 'created_at' => now()->subDays(3)]);
            DB::table('timeline_events')->delete();
            DB::table('contacts')->update(['current_enquiry_id' => null]);
            DB::table('enquiries')->delete();
            DB::table('inbox_response_times')->delete();
        });
        $this->artisan('engage:enquiries:backfill')->assertSuccessful();

        $first = $this->contactId('971501230001');
        $list = collect($this->getJson("/api/v1/contacts/{$first}/enquiries")->assertOk()->json('data'));
        $this->assertSame([['imported', true]], $list->map(fn ($e) => [$e['start_reason'], $e['imported']])->all());
        $this->assertSame(0, $this->tenantContext()->run($this->tenant, fn () => Enquiry::query()->live()->count()), 'reports read live enquiries only');

        // The customer writes now: a live enquiry starts (the imported one is not continued).
        $this->customer('971501230001', 'New question');
        $list = collect($this->getJson("/api/v1/contacts/{$first}/enquiries")->json('data'));
        $this->assertSame([[2, 'first_message', false, true], [1, 'imported', true, false]], $list->map(fn ($e) => [$e['number'], $e['start_reason'], $e['imported'], $e['current']])->all());
        $this->assertSame(1, $this->tenantContext()->run($this->tenant, fn () => Enquiry::query()->live()->count()));

        // Reply times: only the live message started a clock; the imported ones never did.
        $conversation = $this->tenantContext()->run($this->tenant, fn () => DB::table('conversations')->where('contact_id', $first)->value('id'));
        $this->postJson("/api/v1/conversations/{$conversation}/messages", ['type' => 'text', 'body' => 'Hello!'])->assertSuccessful();
        $this->assertSame(1, $this->tenantContext()->run($this->tenant, fn () => DB::table('inbox_response_times')->where('kind', 'first_response')->count()));
    }

    public function test_the_latency_report_measures_received_processed_reply_and_accepted(): void
    {
        $this->customer();
        $this->artisan('engage:webhooks:latency', ['--from' => now()->subHour()->toDateTimeString(), '--to' => now()->addMinute()->toDateTimeString(), '--split' => now()->subMinutes(30)->toDateTimeString()])
            ->expectsOutputToContain('Received → processed')
            ->expectsOutputToContain('Received → reply accepted by Meta')
            ->assertSuccessful();
    }
}
