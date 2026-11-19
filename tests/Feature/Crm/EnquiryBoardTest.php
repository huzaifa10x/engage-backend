<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Access\SystemRole;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Inbox & CRM 3.3, B1: the enquiry card (title, value, expected close, owner) edited without
 * overwriting a teammate, next steps, and the deals board with its figures. Money on the board counts
 * verified payments separately from Wons marked by staff.
 */
final class EnquiryBoardTest extends TestCase
{
    private Tenant $tenant;

    private TenantMembership $owner;

    /** @var array<string, string> */
    private array $stages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->actingAsMember($this->owner);
        $this->stages = collect($this->postJson('/api/v1/crm/stages/suggested')->assertOk()->json('data'))->pluck('id', 'name')->all();
    }

    /** @return array<string, mixed> the contact's enquiry */
    private function enquiry(string $phone, string $name = 'Layla Ahmed'): array
    {
        $contact = $this->postJson('/api/v1/contacts', ['phone' => $phone, 'name' => $name])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/contacts/{$contact}/enquiries")->assertCreated();

        return $this->getJson("/api/v1/contacts/{$contact}/enquiries")->assertOk()->json('data.0');
    }

    public function test_the_enquiry_card_is_edited_without_overwriting_a_teammate(): void
    {
        $e = $this->enquiry('+971501230001');
        $this->assertSame([null, 0], [$e['title'], $e['details_version']]);

        $saved = $this->patchJson("/api/v1/enquiries/{$e['id']}", ['version' => 0, 'title' => 'WhatsApp Business packages', 'value' => 18000, 'value_period' => 'year', 'expected_close_on' => '2026-12-15'])
            ->assertOk()->json('data');
        $this->assertEquals(['WhatsApp Business packages', 18000, 'year', '2026-12-15', 1], [$saved['title'], $saved['value'], $saved['value_period'], $saved['expected_close_on'], $saved['details_version']]);

        // A teammate saves in between: the stale screen gets their name, nothing is overwritten.
        $sara = $this->addMember($this->tenant, SystemRole::Agent);
        $this->tenantContext()->bypass(fn () => DB::table('users')->where('id', $sara->user_id)->update(['name' => 'Sara Rahman']));
        $this->actingAsMember($sara);
        $this->patchJson("/api/v1/enquiries/{$e['id']}", ['version' => 1, 'value' => 20000])->assertOk();
        $this->actingAsMember($this->owner);
        $this->patchJson("/api/v1/enquiries/{$e['id']}", ['version' => 1, 'value' => 15000])->assertStatus(409)
            ->assertJsonPath('error.message', 'This enquiry was updated by Sara Rahman. Their version is shown now; make your change again.');
        $this->assertEquals(20000, $this->getJson("/api/v1/enquiries/{$e['id']}")->json('data.value'));

        $this->patchJson("/api/v1/enquiries/{$e['id']}", ['version' => 2, 'value_period' => 'week'])->assertUnprocessable();
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Viewer));
        $this->patchJson("/api/v1/enquiries/{$e['id']}", ['version' => 2, 'title' => 'x'])->assertForbidden();
    }

    public function test_next_steps_are_added_done_or_cancelled_never_deleted(): void
    {
        $e = $this->enquiry('+971501230002');
        $step = $this->postJson("/api/v1/contacts/{$e['contact_id']}/next-steps", ['enquiry_id' => $e['id'], 'type' => 'quote', 'title' => 'Send the quote', 'due_at' => now()->addDay()->toIso8601String()])
            ->assertCreated()->json('data');
        $this->assertSame(['quote', 'Send quote', 'open', false], [$step['type'], $step['type_label'], $step['status'], $step['overdue']]);
        $this->assertSame($this->owner->id, $step['assigned']['id'], 'assigned to whoever added it');
        $this->assertSame('Send the quote', $this->getJson("/api/v1/enquiries/{$e['id']}")->json('data.next_step.title'));

        $this->postJson("/api/v1/contacts/{$e['contact_id']}/next-steps", ['type' => 'call', 'title' => ''])->assertUnprocessable();
        $this->patchJson("/api/v1/next-steps/{$step['id']}", ['status' => 'done'])->assertOk()->assertJsonPath('data.status', 'done');
        $this->assertNull($this->getJson("/api/v1/enquiries/{$e['id']}")->json('data.next_step'));
        $this->assertCount(0, $this->getJson("/api/v1/contacts/{$e['contact_id']}/next-steps")->json('data'));
        $this->assertCount(1, $this->getJson("/api/v1/contacts/{$e['contact_id']}/next-steps?all=1")->json('data'));

        $late = $this->postJson("/api/v1/contacts/{$e['contact_id']}/next-steps", ['type' => 'call', 'title' => 'Call back', 'due_at' => now()->subHour()->toIso8601String()])->assertCreated();
        $this->assertTrue($late->json('data.overdue'));
        $this->patchJson("/api/v1/next-steps/{$late->json('data.id')}", ['status' => 'cancelled', 'cancel_reason' => 'Customer booked online'])->assertOk()->assertJsonPath('data.cancel_reason', 'Customer booked online');
    }

    public function test_the_board_groups_enquiries_by_stage_with_verified_money_apart_from_marked_wons(): void
    {
        $a = $this->enquiry('+971501230003', 'Layla Ahmed');
        $b = $this->enquiry('+971501230004', 'Omar Khan');
        $c = $this->enquiry('+971501230005', 'Rania Saleh');
        $this->patchJson("/api/v1/enquiries/{$a['id']}", ['version' => 0, 'title' => 'Website redesign', 'value' => 9000])->assertOk();
        $this->patchJson("/api/v1/enquiries/{$b['id']}", ['version' => 0, 'title' => 'Event invitations', 'value' => 3000])->assertOk();
        $this->patchJson("/api/v1/enquiries/{$c['id']}", ['version' => 0, 'title' => 'Landing page', 'value' => 6500])->assertOk();
        $move = fn (array $e, string $stage, array $extra = []) => $this->postJson("/api/v1/contacts/{$e['contact_id']}/stage", ['stage_id' => $this->stages[$stage], 'version' => $this->getJson("/api/v1/enquiries/{$e['id']}")->json('data.stage_version'), 'enquiry_id' => $e['id']] + $extra);

        $move($a, 'Qualified')->assertOk();
        // Dragging to Won asks for a reference (no verified payment) …
        $move($b, 'Won')->assertUnprocessable()->assertJsonPath('error.details.fields.reason.0', 'Add a reference for marking Won without a verified payment (an invoice, receipt or contract number).');
        $move($b, 'Won', ['reason' => 'INV-2209'])->assertOk();
        // … or goes through with a verified payment.
        $this->tenantContext()->run($this->tenant, fn () => DB::table('commerce_orders')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'integration_id' => (string) Str::uuid(), 'store_name' => 'Stripe', 'external_id' => 'pi_1',
            'number' => 'pi_1', 'status' => 'processing', 'currency' => 'AED', 'total' => '6500', 'paid' => true, 'paid_at' => now(), 'placed_at' => now(),
            'contact_id' => $c['contact_id'], 'enquiry_id' => $c['id'], 'match' => 'link', 'created_at' => now(), 'updated_at' => now(),
        ]));
        $move($c, 'Won')->assertOk();

        $board = $this->getJson('/api/v1/enquiries/board')->assertOk()->json('data');
        $columns = collect($board['stages'])->keyBy('name');
        $this->assertSame(['Website redesign'], array_column($columns['Qualified']['enquiries'], 'title'));
        $d = $this->enquiry('+971501230006', 'Nadia Retail');
        $this->assertContains($d['id'], array_column($this->getJson('/api/v1/enquiries/board')->json('data.stages.0.enquiries'), 'id'), 'no stage yet: the first Open column');
        $this->assertSame('Layla Ahmed', $columns['Qualified']['enquiries'][0]['contact']['name']);
        $won = collect($columns['Won']['enquiries'])->keyBy('title');
        $this->assertSame(['INV-2209', false], [$won['Event invitations']['won']['reference'], $won['Event invitations']['verified_paid']]);
        $this->assertEquals([true, 6500], [$won['Landing page']['verified_paid'], $won['Landing page']['paid_total']]);
        $board = $this->getJson('/api/v1/enquiries/board')->json('data');
        $this->assertEquals([9000, 2, 9500, 6500, 2], [
            $board['stats']['open_value'], $board['stats']['open_count'], $board['stats']['won_marked_30d'], $board['stats']['paid_verified_30d'], $board['stats']['open_without_next_step'],
        ]);

        $this->assertCount(2, collect($this->getJson('/api/v1/enquiries/board?filter=no_next_step')->json('data.stages'))->flatMap(fn ($s) => $s['enquiries']));
        $this->assertCount(0, collect($this->getJson('/api/v1/enquiries/board?owner=none&search=nothing-like-this')->json('data.stages'))->flatMap(fn ($s) => $s['enquiries']));
        $this->assertSame(['Event invitations'], collect($this->getJson('/api/v1/enquiries/board?search=omar')->json('data.stages'))->flatMap(fn ($s) => $s['enquiries'])->pluck('title')->all());
    }
}
