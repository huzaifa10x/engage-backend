<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Access\SystemRole;
use App\Domain\Crm\Models\ContactStageEvent;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use Tests\TestCase;

/** CRM pipeline stages and the stage-change log (Change Notice CN-01 §II.1). */
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

    public function test_suggested_stages_are_created_once_in_order(): void
    {
        $this->assertSame(['New', 'Contacted', 'Qualified', 'Won', 'Lost'], array_keys($this->suggested()));
        $this->postJson('/api/v1/crm/stages/suggested')->assertUnprocessable();
    }

    public function test_every_stage_change_is_logged_with_old_stage_new_stage_who_and_when(): void
    {
        $s = $this->suggested();
        $id = $this->contact('+971501110001');

        $this->postJson("/api/v1/contacts/{$id}/stage", ['stage_id' => $s['Contacted']])->assertOk()->assertJsonPath('data.stage.name', 'Contacted');
        $this->travel(2)->days();
        $this->postJson("/api/v1/contacts/{$id}/stage", ['stage_id' => $s['Qualified']])->assertOk();
        $this->postJson("/api/v1/contacts/{$id}/stage", ['stage_id' => $s['Qualified']])->assertOk(); // same stage: no new event

        $events = $this->tenantContext()->run($this->tenant, fn () => ContactStageEvent::query()->orderBy('changed_at')->get());
        $this->assertCount(2, $events);
        $this->assertNull($events[0]->from_stage_id);
        $this->assertSame($s['Contacted'], $events[0]->to_stage_id);
        $this->assertSame($s['Contacted'], $events[1]->from_stage_id);
        $this->assertSame($s['Qualified'], $events[1]->to_stage_id);
        $this->assertSame($this->owner->id, $events[1]->changed_by_membership_id);
        $this->assertTrue($events[1]->changed_at->gt($events[0]->changed_at));

        $this->getJson("/api/v1/contacts/{$id}/stage-history")->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.from.name', 'Contacted')
            ->assertJsonPath('data.0.to.name', 'Qualified');

        $this->getJson("/api/v1/contacts?stage_id={$s['Qualified']}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/contacts?stage_id=none')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_used_stage_is_archived_not_deleted_and_an_unused_one_is_deleted(): void
    {
        $s = $this->suggested();
        $id = $this->contact('+971501110002');
        $this->postJson("/api/v1/contacts/{$id}/stage", ['stage_id' => $s['Won']])->assertOk();

        $this->deleteJson("/api/v1/crm/stages/{$s['Won']}")->assertUnprocessable(); // contact still on it
        $this->postJson("/api/v1/contacts/{$id}/stage", ['stage_id' => null])->assertOk()->assertJsonPath('data.stage', null);
        $this->deleteJson("/api/v1/crm/stages/{$s['Won']}")->assertNoContent();
        $this->deleteJson("/api/v1/crm/stages/{$s['Lost']}")->assertNoContent();

        $all = collect($this->getJson('/api/v1/crm/stages?include_archived=1')->json('data'))->keyBy('name');
        $this->assertTrue($all['Won']['archived'], 'kept for history');
        $this->assertFalse($all->has('Lost'), 'never used: deleted');
        $this->postJson("/api/v1/contacts/{$id}/stage", ['stage_id' => $s['Won']])->assertUnprocessable();
        $this->getJson("/api/v1/contacts/{$id}/stage-history")->assertOk()->assertJsonPath('data.1.to.name', 'Won');
    }

    public function test_names_are_unique_and_order_can_change(): void
    {
        $s = $this->suggested();
        $this->postJson('/api/v1/crm/stages', ['name' => 'won'])->assertUnprocessable();
        $booked = $this->postJson('/api/v1/crm/stages', ['name' => 'Booked', 'color' => 'good'])->assertCreated()->json('data.id');

        $order = [$s['New'], $s['Contacted'], $s['Qualified'], $booked, $s['Won'], $s['Lost']];
        $this->putJson('/api/v1/crm/stages/order', ['ids' => $order])->assertOk();
        $this->assertSame($order, collect($this->getJson('/api/v1/crm/stages')->json('data'))->pluck('id')->all());
        $this->putJson('/api/v1/crm/stages/order', ['ids' => [$booked]])->assertUnprocessable();
    }

    public function test_agents_can_move_contacts_but_not_manage_stages(): void
    {
        $s = $this->suggested();
        $id = $this->contact('+971501110003');

        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Agent));
        $this->postJson("/api/v1/contacts/{$id}/stage", ['stage_id' => $s['Contacted']])->assertOk();
        $this->postJson('/api/v1/crm/stages', ['name' => 'Hot'])->assertForbidden();
        $this->deleteJson("/api/v1/crm/stages/{$s['New']}")->assertForbidden();
    }

    public function test_stages_are_isolated_per_workspace(): void
    {
        $s = $this->suggested();
        $other = $this->createTenant();
        $this->subscribe($other, 'pro');
        $this->actingAsMember($this->addMember($other));
        $id = $this->contact('+971501110004');

        $this->getJson('/api/v1/crm/stages')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson("/api/v1/contacts/{$id}/stage", ['stage_id' => $s['New']])->assertUnprocessable();
        $this->patchJson("/api/v1/crm/stages/{$s['New']}", ['name' => 'Mine'])->assertNotFound();
    }
}
