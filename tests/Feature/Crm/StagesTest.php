<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Access\SystemRole;
use App\Domain\Crm\Models\ContactStageEvent;
use App\Domain\Crm\Models\CrmStage;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Timeline\Enquiry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Lead stages (batch 6, item 4; first built for CN-01): a stage per enquiry, in groups, versioned so two
 * people can't overwrite each other, Won only with proof, every change logged and on the timeline.
 */
final class StagesTest extends TestCase
{
    private Tenant $tenant;

    private TenantMembership $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->actingAsMember($this->owner);
    }

    private function contact(string $phone): string
    {
        return (string) $this->postJson('/api/v1/contacts', ['phone' => $phone])->assertCreated()->json('data.id');
    }

    /** @return array<string, string> name => id */
    private function suggested(): array
    {
        return collect($this->postJson('/api/v1/crm/stages/suggested')->assertOk()->json('data'))->pluck('id', 'name')->all();
    }

    /** @return array<string, mixed> the contact's current enquiry as the contact screen loads it */
    private function current(string $contactId): array
    {
        return collect($this->getJson("/api/v1/contacts/{$contactId}/enquiries")->assertOk()->json('data'))->firstWhere('current', true) ?? ['stage_version' => 0];
    }

    /** @param array<string, mixed> $extra */
    private function move(string $contactId, ?string $stageId, array $extra = []): TestResponse
    {
        return $this->postJson("/api/v1/contacts/{$contactId}/stage", ['stage_id' => $stageId, 'version' => $this->current($contactId)['stage_version']] + $extra);
    }

    public function test_the_default_stages_come_in_order_with_their_groups_and_new_workspaces_get_them(): void
    {
        $stages = collect($this->postJson('/api/v1/crm/stages/suggested')->assertOk()->json('data'));
        $this->assertSame(['New', 'Qualified', 'Quote sent', 'Booked', 'Won', 'Lost'], $stages->pluck('name')->all());
        $this->assertSame(['open', 'open', 'open', 'open', 'won', 'lost'], $stages->pluck('group')->all());
        $this->postJson('/api/v1/crm/stages/suggested')->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'])->postJson('/api/v1/auth/register', [
            'name' => 'Jane Doe', 'email' => 'jane@fastwheels.ae', 'password' => 'Secret123456', 'password_confirmation' => 'Secret123456', 'company_name' => 'Fast Wheels',
        ])->assertCreated();
        $new = $this->tenantContext()->bypass(fn () => Tenant::query()->where('billing_email', 'jane@fastwheels.ae')->sole());
        $this->assertSame(['New', 'Qualified', 'Quote sent', 'Booked', 'Won', 'Lost'],
            $this->tenantContext()->run($new, fn () => CrmStage::query()->orderBy('position')->pluck('name')->all()));
    }

    public function test_stages_can_be_renamed_and_added_and_each_belongs_to_a_group(): void
    {
        $s = $this->suggested();
        $this->patchJson("/api/v1/crm/stages/{$s['Won']}", ['name' => 'Rented'])->assertOk()->assertJsonPath('data.group', 'won');
        $this->postJson('/api/v1/crm/stages', ['name' => 'No show', 'group' => 'lost'])->assertCreated()->assertJsonPath('data.group', 'lost');
        $this->postJson('/api/v1/crm/stages', ['name' => 'Odd', 'group' => 'maybe'])->assertUnprocessable();
        $this->postJson('/api/v1/crm/stages', ['name' => 'won'])->assertCreated()->assertJsonPath('data.group', 'open'); // "Won" was renamed
    }

    public function test_a_lead_moves_through_every_stage_on_its_enquiry_and_each_change_is_on_the_timeline(): void
    {
        $s = $this->suggested();
        $id = $this->contact('+971501110001');

        foreach (['New', 'Qualified', 'Quote sent', 'Booked'] as $name) {
            $this->move($id, $s[$name])->assertOk()->assertJsonPath('data.stage.name', $name);
        }
        $this->move($id, $s['Lost'], ['reason' => 'Chose another company'])->assertOk();

        $enquiry = $this->current($id);
        $this->assertSame(['name' => 'Lost', 'group' => 'lost'], array_intersect_key($enquiry['stage'], ['name' => 1, 'group' => 1]));
        $this->assertSame(5, $enquiry['stage_version']);
        $this->assertSame('Chose another company', $enquiry['lost_reason']);

        $events = $this->tenantContext()->run($this->tenant, fn () => ContactStageEvent::query()->orderBy('version')->get());
        $this->assertSame([1, 2, 3, 4, 5], $events->pluck('version')->all());
        $this->assertSame([$enquiry['id']], $events->pluck('enquiry_id')->unique()->values()->all());
        $this->assertSame($this->owner->id, $events->last()?->changed_by_membership_id);

        $timeline = collect($this->getJson("/api/v1/contacts/{$id}/timeline?group=stages")->assertOk()->json('data'));
        $this->assertSame(5, $timeline->count());
        $last = $timeline->first(); // newest first
        $this->assertEquals(['from' => 'Booked', 'to' => 'Lost', 'version' => 5, 'note' => 'Chose another company', 'to_group' => 'lost'],
            array_intersect_key($last['data'], ['from' => 1, 'to' => 1, 'version' => 1, 'note' => 1, 'to_group' => 1]));
        $this->assertSame($enquiry['id'], $last['enquiry_id']);
        $this->assertSame('agent', $last['actor']);

        $this->getJson("/api/v1/contacts/{$id}/stage-history")->assertOk()->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.reason', 'Chose another company')->assertJsonPath('data.0.version', 5);
        $this->getJson("/api/v1/contacts?stage_id={$s['Lost']}")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_two_people_changing_the_same_lead_at_once_cannot_overwrite_each_other(): void
    {
        $s = $this->suggested();
        $id = $this->contact('+971501110002');
        $this->move($id, $s['New'])->assertOk();
        $loaded = $this->current($id)['stage_version']; // both screens loaded version 1

        $sara = $this->addMember($this->tenant, SystemRole::Agent);
        $this->tenantContext()->bypass(fn () => DB::table('users')->where('id', $sara->user_id)->update(['name' => 'Sara Ahmed']));
        $this->actingAsMember($sara);
        $this->postJson("/api/v1/contacts/{$id}/stage", ['stage_id' => $s['Qualified'], 'version' => $loaded])->assertOk();

        $this->actingAsMember($this->owner);
        $this->postJson("/api/v1/contacts/{$id}/stage", ['stage_id' => $s['Quote sent'], 'version' => $loaded])->assertStatus(409)
            ->assertJsonPath('error.code', 'conflict')
            ->assertJsonPath('error.message', 'This lead was updated by Sara Ahmed. Check its stage and try again.');
        $this->assertSame('Qualified', $this->current($id)['stage']['name'], 'not overwritten');
        $this->postJson("/api/v1/contacts/{$id}/stage", ['stage_id' => $s['Quote sent']])->assertUnprocessable(); // the version is required
    }

    public function test_won_needs_a_verified_payment_or_a_reference_from_anyone_who_replies(): void
    {
        $s = $this->suggested();
        $id = $this->contact('+971501110003');
        $this->move($id, $s['Booked'])->assertOk();

        // An Agent (any role that can reply), no payment: a reference of at least 3 characters is required.
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Agent));
        $this->move($id, $s['Won'])->assertUnprocessable()->assertJsonPath('error.details.fields.reason.0', 'Add a reference for marking Won without a verified payment (an invoice, receipt or contract number).');
        $this->move($id, $s['Won'], ['reason' => 'ab'])->assertUnprocessable();
        $this->move($id, $s['Won'], ['reason' => 'Receipt 4471'])->assertOk()->assertJsonPath('data.stage.name', 'Won');
        $this->assertSame('Receipt 4471', $this->tenantContext()->run($this->tenant, fn () => ContactStageEvent::query()->where('to_stage_id', $s['Won'])->value('reason')));

        // A Marketing teammate can too; a Viewer cannot change stages at all.
        $third = $this->contact('+971501110013');
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Marketing));
        $this->move($third, $s['Won'], ['reason' => 'INV-2209'])->assertOk();
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Viewer));
        $this->move($third, $s['Lost'], ['reason' => 'Too expensive'])->assertForbidden();
        $this->actingAsMember($this->owner);

        // With a verified payment (a paid store order on the enquiry), an Agent can mark Won.
        $other = $this->contact('+971501110004');
        $this->move($other, $s['Booked'])->assertOk();
        $enquiry = $this->current($other)['id'];
        $this->tenantContext()->run($this->tenant, fn () => DB::table('commerce_orders')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'integration_id' => (string) Str::uuid(), 'store_name' => 'Store', 'external_id' => '1',
            'number' => '1001', 'status' => 'processing', 'currency' => 'AED', 'total' => '500', 'paid' => true, 'paid_at' => now(), 'placed_at' => now(),
            'contact_id' => $other, 'enquiry_id' => $enquiry, 'match' => 'link', 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Agent));
        $this->move($other, $s['Won'])->assertOk()->assertJsonPath('data.stage.name', 'Won');
    }

    public function test_lost_needs_a_reason(): void
    {
        $s = $this->suggested();
        $id = $this->contact('+971501110005');
        $this->move($id, $s['Lost'])->assertUnprocessable()->assertJsonPath('error.details.fields.reason.0', 'Say why this lead was lost.');
        $this->move($id, $s['Lost'], ['reason' => 'Too expensive'])->assertOk();
    }

    public function test_each_enquiry_keeps_its_own_stage(): void
    {
        $s = $this->suggested();
        $id = $this->contact('+971501110006');
        $this->move($id, $s['Lost'], ['reason' => 'Dates changed'])->assertOk();
        $first = $this->current($id)['id'];
        $this->postJson("/api/v1/contacts/{$id}/enquiries", ['note' => 'June rental'])->assertCreated();
        $this->move($id, $s['Qualified'])->assertOk();

        $list = collect($this->getJson("/api/v1/contacts/{$id}/enquiries")->json('data'))->keyBy('id');
        $this->assertSame('Lost', $list[$first]['stage']['name']);
        $this->assertSame('Qualified', $list->firstWhere('current', true)['stage']['name']);
        $this->getJson("/api/v1/contacts/{$id}")->assertOk()->assertJsonPath('data.stage.name', 'Qualified');

        // An earlier enquiry can still be moved on its own.
        $this->postJson("/api/v1/contacts/{$id}/stage", ['stage_id' => $s['New'], 'version' => 1, 'enquiry_id' => $first])->assertOk();
        $this->assertSame('New', collect($this->getJson("/api/v1/contacts/{$id}/enquiries")->json('data'))->firstWhere('id', $first)['stage']['name']);
        $this->getJson("/api/v1/contacts/{$id}")->assertOk()->assertJsonPath('data.stage.name', 'Qualified');
    }

    public function test_a_used_stage_is_archived_not_deleted_and_an_unused_one_is_deleted(): void
    {
        $s = $this->suggested();
        $id = $this->contact('+971501110007');
        $this->move($id, $s['Booked'])->assertOk();

        $this->deleteJson("/api/v1/crm/stages/{$s['Booked']}")->assertUnprocessable(); // contact still on it
        $this->move($id, null)->assertOk()->assertJsonPath('data.stage', null);
        $this->deleteJson("/api/v1/crm/stages/{$s['Booked']}")->assertNoContent();
        $this->deleteJson("/api/v1/crm/stages/{$s['Quote sent']}")->assertNoContent();

        $all = collect($this->getJson('/api/v1/crm/stages?include_archived=1')->json('data'))->keyBy('name');
        $this->assertTrue($all['Booked']['archived'], 'kept for history');
        $this->assertFalse($all->has('Quote sent'), 'never used: deleted');
        $this->move($id, $s['Booked'])->assertUnprocessable();
    }

    public function test_archiving_checks_every_enquiry_and_keeps_one_open_won_and_lost_stage(): void
    {
        $s = $this->suggested();
        $id = $this->contact('+971501110017');
        $this->move($id, $s['Quote sent'])->assertOk();
        $first = $this->current($id)['id'];
        // A second enquiry becomes the current one and moves to New: only the first stays on "Quote sent".
        $this->postJson("/api/v1/contacts/{$id}/enquiries")->assertCreated();
        $this->move($id, $s['New'], ['version' => 0])->assertOk();
        $this->getJson("/api/v1/contacts/{$id}")->assertJsonPath('data.stage.name', 'New');

        $this->deleteJson("/api/v1/crm/stages/{$s['Quote sent']}")->assertUnprocessable()
            ->assertJsonPath('error.details.fields.stage.0', '1 enquiry is in this stage. Move it to another stage first.');
        $this->assertSame(1, collect($this->getJson('/api/v1/crm/stages')->json('data'))->firstWhere('id', $s['Quote sent'])['enquiries']);
        $this->postJson("/api/v1/contacts/{$id}/stage", ['stage_id' => $s['New'], 'version' => 1, 'enquiry_id' => $first])->assertOk();
        $this->deleteJson("/api/v1/crm/stages/{$s['Quote sent']}")->assertNoContent();

        // The only Won and Lost stages cannot be archived or moved to another group.
        $this->deleteJson("/api/v1/crm/stages/{$s['Won']}")->assertUnprocessable()
            ->assertJsonPath('error.details.fields.stage.0', 'Archiving this stage would leave no Won stage. Keep at least one Open, one Won and one Lost stage.');
        $this->patchJson("/api/v1/crm/stages/{$s['Lost']}", ['group' => 'open'])->assertUnprocessable();
        $paid = $this->postJson('/api/v1/crm/stages', ['name' => 'Paid', 'group' => 'won'])->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/crm/stages/{$s['Won']}")->assertNoContent();
        $this->assertSame('won', collect($this->getJson('/api/v1/crm/stages')->json('data'))->firstWhere('id', $paid)['group']);

        // An archived stage comes back with Restore.
        $this->postJson("/api/v1/crm/stages/{$s['Quote sent']}/restore")->assertOk()->assertJsonPath('data.name', 'Quote sent');
        $this->postJson("/api/v1/crm/stages/{$s['Quote sent']}/restore")->assertStatus(422);
    }

    public function test_names_are_unique_and_order_can_change(): void
    {
        $s = $this->suggested();
        $this->postJson('/api/v1/crm/stages', ['name' => 'won'])->assertUnprocessable();
        $hot = $this->postJson('/api/v1/crm/stages', ['name' => 'Hot', 'color' => 'bad'])->assertCreated()->json('data.id');

        $order = [$s['New'], $hot, $s['Qualified'], $s['Quote sent'], $s['Booked'], $s['Won'], $s['Lost']];
        $this->putJson('/api/v1/crm/stages/order', ['ids' => $order])->assertOk();
        $this->assertSame($order, collect($this->getJson('/api/v1/crm/stages')->json('data'))->pluck('id')->all());
        $this->putJson('/api/v1/crm/stages/order', ['ids' => [$hot]])->assertUnprocessable();
    }

    public function test_agents_can_move_leads_but_not_manage_stages_and_viewers_cannot_move_them(): void
    {
        $s = $this->suggested();
        $id = $this->contact('+971501110008');

        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Agent));
        $this->move($id, $s['Qualified'])->assertOk();
        $this->postJson('/api/v1/crm/stages', ['name' => 'Hot'])->assertForbidden();
        $this->deleteJson("/api/v1/crm/stages/{$s['New']}")->assertForbidden();

        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Viewer));
        $this->postJson("/api/v1/contacts/{$id}/stage", ['stage_id' => $s['New'], 'version' => 1])->assertForbidden();
    }

    public function test_stages_are_isolated_per_workspace(): void
    {
        $s = $this->suggested();
        $other = $this->createTenant();
        $this->subscribe($other, 'pro');
        $this->actingAsMember($this->addMember($other));
        $id = $this->contact('+971501110009');

        $this->getJson('/api/v1/crm/stages')->assertOk()->assertJsonCount(0, 'data');
        $this->move($id, $s['New'])->assertUnprocessable();
        $this->patchJson("/api/v1/crm/stages/{$s['New']}", ['name' => 'Mine'])->assertNotFound();
        $this->assertSame(0, $this->tenantContext()->run($other, fn () => Enquiry::query()->whereNotNull('stage_id')->count()));
    }
}
