<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\SystemRole;
use App\Domain\Crm\Models\CrmStage;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timeline\Enquiries;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Inbox & CRM 3.5: the contact record (lifecycle, lead status, field history, duplicates) and search-first start conversation. */
final class ContactRecordTest extends TestCase
{
    use InteractsWithWhatsapp;

    private const ERRORS = 'error.details.fields';

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant(['timezone' => 'Asia/Dubai']);
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
        Http::fake(['graph.facebook.com/*' => Http::response(['messaging_product' => 'whatsapp', 'contacts' => [['wa_id' => '971501234567']], 'messages' => [['id' => 'wamid.X'.bin2hex(random_bytes(4))]]])]);
        $this->actingAsMember($this->owner);
    }

    private function template(): array
    {
        return ['type' => 'template', 'template' => ['name' => 'hello_world', 'language' => 'en_US']];
    }

    public function test_fields_edit_with_history_and_lifecycle_and_lead_status_are_worked_out(): void
    {
        $id = $this->postJson('/api/v1/contacts', ['phone' => '+971501112233', 'name' => 'Layla'])->assertCreated()->json('data.id');
        $this->getJson("/api/v1/contacts/{$id}")->assertOk()
            ->assertJsonPath('data.lifecycle.key', 'lead')->assertJsonPath('data.lead_status.key', 'new')->assertJsonPath('data.lead_status.automatic', true);

        $this->patchJson("/api/v1/contacts/{$id}", ['company' => 'Luxe Interiors', 'city' => 'Dubai', 'lead_status' => 'bad_timing', 'owner_membership_id' => $this->owner->id])->assertOk()
            ->assertJsonPath('data.company', 'Luxe Interiors')->assertJsonPath('data.lead_status.label', 'Bad timing')
            ->assertJsonPath('data.lead_status.automatic', false)->assertJsonPath('data.owner.id', $this->owner->id);
        $this->patchJson("/api/v1/contacts/{$id}", ['company' => 'Luxe Interiors'])->assertOk();          // unchanged: no new row
        $this->patchJson("/api/v1/contacts/{$id}", ['lead_status' => 'nope'])->assertUnprocessable();

        $changes = $this->getJson("/api/v1/contacts/{$id}/changes")->assertOk()->json('data');
        $this->assertCount(4, $changes);
        $byField = collect($changes)->keyBy('field');
        $this->assertSame([null, 'Luxe Interiors'], [$byField['company']['from'], $byField['company']['to']]);
        $this->assertSame('Bad timing', $byField['lead_status']['to']);
        $this->assertSame($this->owner->id, $byField['city']['by']['id']);

        // A won enquiry makes them a Customer, who has no lead status.
        $this->tenantContext()->run($this->tenant, function () use ($id) {
            $won = CrmStage::query()->create(['name' => 'Won', 'color' => 'good', 'group' => 'won', 'position' => 9])->id;
            app(Enquiries::class)->manual(Contact::query()->findOrFail($id))->forceFill(['stage_id' => $won])->save();
        });
        $this->getJson("/api/v1/contacts/{$id}")->assertJsonPath('data.lifecycle.label', 'Customer')->assertJsonPath('data.lead_status.key', null);
        $this->patchJson("/api/v1/contacts/{$id}", ['lead_status' => 'new'])->assertUnprocessable()->assertJsonValidationErrors('lead_status', self::ERRORS);
    }

    public function test_duplicates_by_phone_and_email(): void
    {
        $a = $this->postJson('/api/v1/contacts', ['phone' => '+971501112233', 'name' => 'Layla', 'email' => 'layla@example.com'])->json('data.id');
        $b = $this->postJson('/api/v1/contacts', ['phone' => '+971509998877', 'name' => 'Layla H', 'email' => 'LAYLA@example.com'])->json('data.id');
        $this->postJson('/api/v1/contacts', ['phone' => '+971507776655', 'name' => 'Omar']);

        $this->getJson("/api/v1/contacts/{$a}/duplicates")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $b)->assertJsonPath('data.0.match', 'email');
    }

    public function test_search_says_what_starting_a_chat_will_do(): void
    {
        $this->postJson('/api/v1/contacts', ['phone' => '+971501112233', 'name' => 'Layla', 'attributes' => []]);
        $rows = $this->getJson('/api/v1/contacts/search?q=050 111 2233')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame(['Layla', 'start_chat', false], [$rows[0]['display_name'], $rows[0]['action'], $rows[0]['has_permission']]);

        // The customer writes: the chat is open.
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['from' => '971501112233', 'text' => ['body' => 'Hi'], 'timestamp' => (string) now()->timestamp], bsuid: null)))->assertOk();
        $this->getJson('/api/v1/contacts/search?q=layla')->assertJsonPath('data.0.action', 'open_chat')->assertJsonPath('data.0.has_permission', true);
        $this->travel(25)->hours();
        $this->getJson('/api/v1/contacts/search?q=layla')->assertJsonPath('data.0.action', 'send_template');
        $this->assertCount(1, $this->getJson('/api/v1/contacts/search')->json('data'));   // most recent when empty
    }

    public function test_start_a_chat_with_a_new_contact(): void
    {
        $body = ['phone_number_id' => $this->number->id, 'to' => '+971 50 222 3344'] + $this->template();

        $this->postJson('/api/v1/messages', $body + ['new_contact' => ['name' => 'R2']])->assertUnprocessable()->assertJsonValidationErrors(['new_contact.name'], self::ERRORS);
        $this->postJson('/api/v1/messages', $body + ['new_contact' => ['name' => 'Sara']])->assertUnprocessable()->assertJsonValidationErrors(['permission.via'], self::ERRORS);
        $this->postJson('/api/v1/messages', $body + ['new_contact' => ['name' => 'Sara'], 'permission' => ['via' => 'other']])->assertUnprocessable()->assertJsonValidationErrors('permission.note', self::ERRORS);

        $this->withHeader('Idempotency-Key', 'k1')->postJson('/api/v1/messages', $body + [
            'new_contact' => ['name' => 'Sara Ahmed', 'company' => 'Bloom', 'tags' => ['vip']],
            'permission' => ['via' => 'call'], 'topic' => 'Wedding photography', 'create_enquiry' => true,
        ])->assertAccepted();

        $contact = $this->tenantContext()->run($this->tenant, fn () => Contact::query()->where('wa_id', '971502223344')->sole());
        $this->assertSame(['Sara Ahmed', 'Bloom', $this->owner->id, 'call'], [$contact->name, $contact->company, $contact->owner_membership_id, $contact->contact_permission['via']]);
        $this->assertSame('Wedding photography', $this->tenantContext()->run($this->tenant, fn () => DB::table('enquiries')->where('contact_id', $contact->id)->value('title')));
        $this->assertContains('Permission to contact', array_column($this->getJson("/api/v1/contacts/{$contact->id}/changes")->json('data'), 'label'));

        // The same number again from the "new contact" form: already saved.
        $this->postJson('/api/v1/messages', $body + ['new_contact' => ['name' => 'Sara'], 'permission' => ['via' => 'call']])->assertUnprocessable()
            ->assertJsonValidationErrors(['to' => 'Already saved as Sara Ahmed.'], self::ERRORS);
    }

    public function test_an_existing_contact_with_no_chat_needs_permission_and_the_owner_hears_about_it(): void
    {
        $agent = $this->addMember($this->tenant, SystemRole::Agent);
        $this->tenantContext()->run($this->tenant, fn () => app(NumberAccess::class)->setGrants($agent, [$this->number->id]));
        $id = $this->postJson('/api/v1/contacts', ['phone' => '+971503334455', 'name' => 'Omar'])->json('data.id');
        $this->patchJson("/api/v1/contacts/{$id}", ['owner_membership_id' => $this->owner->id])->assertOk();

        $this->actingAsMember($agent);
        $body = ['phone_number_id' => $this->number->id, 'contact_id' => $id] + $this->template();
        $this->postJson('/api/v1/messages', $body)->assertUnprocessable()->assertJsonValidationErrors('permission.via', self::ERRORS);
        $this->withHeader('Idempotency-Key', 'k2')->postJson('/api/v1/messages', $body + ['permission' => ['via' => 'in_person']])->assertAccepted();

        $this->assertSame($this->owner->id, $this->tenantContext()->run($this->tenant, fn () => Contact::query()->findOrFail($id)->owner_membership_id));
        $this->assertTrue($this->tenantContext()->run($this->tenant, fn () => DB::table('member_notifications')->where('membership_id', $this->owner->id)->where('title', 'like', '%started a chat with your customer Omar%')->exists()));
    }
}
