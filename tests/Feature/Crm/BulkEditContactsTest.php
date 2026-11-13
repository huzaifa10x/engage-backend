<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Domain\Access\SystemRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Crm\Models\ContactField;
use App\Domain\Crm\Models\ContactStageEvent;
use App\Domain\Messaging\Models\ConsentEvent;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Contacts & CRM › select contacts › Edit. */
final class BulkEditContactsTest extends TestCase
{
    private Tenant $tenant;

    private TenantMembership $owner;

    /** @var list<string> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->actingAsMember($this->owner);
        foreach (['+971501110001', '+971501110002', '+971501110003'] as $i => $phone) {
            $this->ids[] = (string) $this->postJson('/api/v1/contacts', ['phone' => $phone, 'name' => "Guest {$i}", 'tags' => ['Lead']])->assertCreated()->json('data.id');
        }
    }

    /** @param array<string, mixed> $changes */
    private function edit(array $changes, ?array $ids = null, ?int $confirm = null): TestResponse
    {
        $ids ??= $this->ids;

        return $this->postJson('/api/v1/contacts/bulk-edit', ['contact_ids' => $ids, 'confirm_count' => $confirm ?? count($ids)] + $changes);
    }

    /** @return Collection<int, Contact> */
    private function contacts(): Collection
    {
        return $this->tenantContext()->run($this->tenant, fn () => Contact::query()->whereIn('id', $this->ids)->orderBy('wa_id')->get());
    }

    public function test_tags_and_a_custom_field_are_changed_on_every_selected_contact(): void
    {
        $this->postJson('/api/v1/contact-fields', ['label' => 'City'])->assertCreated();

        $this->edit(['tags_add' => ['VIP'], 'tags_remove' => ['lead'], 'field' => ['key' => 'city', 'value' => 'Dubai']])
            ->assertOk()->assertJsonPath('data.matched', 3)->assertJsonPath('data.updated', 3);

        foreach ($this->contacts() as $c) {
            $this->assertSame(['VIP'], $c->tags);
            $this->assertSame('Dubai', $c->custom_fields['city']);
        }

        // The same change again changes nothing; clearing the field removes it.
        $this->edit(['tags_add' => ['VIP']])->assertJsonPath('data.updated', 0);
        $this->edit(['field' => ['key' => 'city', 'value' => null]])->assertJsonPath('data.updated', 3);
        $this->assertNull($this->contacts()->first()->custom_fields['city'] ?? null);
        $this->assertSame(3, $this->tenantContext()->run($this->tenant, fn () => AuditLog::query()->where('action', 'contact.bulk_updated')->count()));
    }

    public function test_the_confirmed_count_must_still_match_and_edits_are_validated_before_anything_changes(): void
    {
        // A contact deleted after the teammate confirmed: nothing changes, they confirm again.
        $this->deleteJson("/api/v1/contacts/{$this->ids[2]}")->assertNoContent();
        $this->edit(['tags_add' => ['VIP']], confirm: 3)->assertStatus(422);
        $this->assertSame(['Lead'], $this->contacts()->first()->tags);

        $this->edit([])->assertStatus(422); // no change chosen
        $this->edit(['tags_add' => ['VIP'], 'tags_remove' => ['vip']])->assertStatus(422);
        $this->edit(['tags_add' => ['<script>']])->assertStatus(422);
        $this->edit(['field' => ['key' => 'nope', 'value' => 'x']])->assertStatus(422);

        $this->postJson('/api/v1/contact-fields', ['label' => 'Budget', 'type' => 'number'])->assertCreated();
        $this->tenantContext()->run($this->tenant, fn () => ContactField::query()->where('key', 'budget')->update(['rules' => ['required' => true]]));
        $this->edit(['field' => ['key' => 'budget', 'value' => 'lots']], array_slice($this->ids, 0, 2))->assertStatus(422);
        $this->edit(['field' => ['key' => 'budget', 'value' => '']], array_slice($this->ids, 0, 2))->assertStatus(422); // required: cannot clear
        $this->edit(['field' => ['key' => 'budget', 'value' => '2500']], array_slice($this->ids, 0, 2))->assertOk()->assertJsonPath('data.updated', 2);
    }

    public function test_stage_changes_are_logged_per_contact_and_kept_for_owners_and_admins(): void
    {
        $stages = collect($this->postJson('/api/v1/crm/stages/suggested')->assertOk()->json('data'))->pluck('id', 'name');

        $this->edit(['stage' => ['id' => $stages['Qualified']]])->assertOk()->assertJsonPath('data.updated', 3);
        $this->assertSame(3, $this->tenantContext()->run($this->tenant, fn () => ContactStageEvent::query()->where('changed_by_membership_id', $this->owner->id)->count()));

        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Agent));
        $this->edit(['stage' => ['id' => $stages['Won']]])->assertStatus(422);
        $this->edit(['tags_add' => ['Called']])->assertOk();

        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Viewer));
        $this->edit(['tags_add' => ['Called']])->assertForbidden();
    }

    public function test_a_bulk_opt_in_needs_its_source_and_never_resubscribes_someone_who_opted_out(): void
    {
        $this->postJson("/api/v1/contacts/{$this->ids[0]}/consent", ['state' => 'opted_out', 'notify' => false])->assertOk();

        $this->edit(['consent' => ['state' => 'opted_in']])->assertStatus(422);
        $this->edit(['consent' => ['state' => 'opted_in', 'note' => 'Signed the paper form at the open day']])
            ->assertOk()->assertJsonPath('data.updated', 2)->assertJsonPath('data.skipped.opted_out', 1);

        $states = $this->contacts()->pluck('consent_state')->map->value->all();
        $this->assertSame(['opted_out', 'opted_in', 'opted_in'], $states);
        $this->assertSame(2, $this->tenantContext()->run($this->tenant, fn () => ConsentEvent::query()->where('action', 'opted_in')->where('detail', 'Signed the paper form at the open day')->count()));

        $this->edit(['consent' => ['state' => 'opted_out']])->assertOk()->assertJsonPath('data.updated', 2);
        $this->assertSame(['opted_out', 'opted_out', 'opted_out'], $this->contacts()->pluck('consent_state')->map->value->all());
    }
}
