<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Domain\Access\SystemRole;
use App\Domain\Messaging\Models\ConsentEvent;
use App\Domain\Messaging\Models\Contact;
use Tests\TestCase;

final class ContactsTest extends TestCase
{
    public function test_contacts_crud_search_and_consent(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->addMember($tenant);
        $this->actingAsMember($owner);

        $id = $this->postJson('/api/v1/contacts', ['phone' => '+971 50 123 4567', 'name' => 'Omar Khalid', 'opted_in' => true])
            ->assertCreated()->assertJsonPath('data.phone', '+971501234567')->assertJsonPath('data.consent_state', 'opted_in')->json('data.id');

        $this->postJson('/api/v1/contacts', ['phone' => '00971501234567'])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->postJson('/api/v1/contacts', ['phone' => '12'])->assertStatus(422);

        $this->getJson('/api/v1/contacts?q=omar')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/contacts?q=4567')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/contacts?q=nobody')->assertOk()->assertJsonCount(0, 'data');

        $this->patchJson("/api/v1/contacts/{$id}", ['email' => 'omar@example.com'])->assertOk()->assertJsonPath('data.email', 'omar@example.com');

        $this->postJson("/api/v1/contacts/{$id}/consent", ['state' => 'opted_out', 'note' => 'Asked by phone'])
            ->assertOk()->assertJsonPath('data.consent_state', 'opted_out');
        $this->assertSame(2, $this->tenantContext()->run($tenant, fn () => ConsentEvent::query()->count()));

        $this->deleteJson("/api/v1/contacts/{$id}")->assertNoContent();
        $this->getJson("/api/v1/contacts/{$id}")->assertNotFound();

        // Re-adding a deleted number restores the same contact (history stays attached).
        $this->postJson('/api/v1/contacts', ['phone' => '+971501234567'])->assertCreated()->assertJsonPath('data.id', $id);
    }

    public function test_agents_cannot_delete_contacts_and_viewers_cannot_create(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->addMember($tenant);
        $id = $this->actingAsMember($owner)->postJson('/api/v1/contacts', ['phone' => '+971501234567'])->json('data.id');

        $this->actingAsMember($this->addMember($tenant, SystemRole::Agent))->deleteJson("/api/v1/contacts/{$id}")->assertForbidden();
        $this->actingAsMember($this->addMember($tenant, SystemRole::Viewer))->postJson('/api/v1/contacts', ['phone' => '+971509999999'])->assertForbidden();
    }

    public function test_numbers_are_checked_against_the_country_rules_and_local_numbers_use_the_workspace_country(): void
    {
        $tenant = $this->createTenant(['country' => 'PK']);
        $this->actingAsMember($this->addMember($tenant));

        // QA: "0333 1370070" was saved as +3331370070. In a Pakistani workspace it is a local mobile number.
        $this->postJson('/api/v1/contacts', ['phone' => '0333 1370070'])->assertCreated()->assertJsonPath('data.phone', '+923331370070');
        $this->postJson('/api/v1/contacts', ['phone' => '+3331370070'])->assertStatus(422)
            ->assertJsonPath('error.details.fields.phone.0', 'This is not a valid phone number. Include the country code, for example +92 300 1234567.');
        $this->postJson('/api/v1/contacts', ['phone' => '+9212 uhuhuh'])->assertStatus(422);
        $this->postJson('/api/v1/contacts', ['phone' => '971501234567'])->assertCreated()->assertJsonPath('data.phone', '+971501234567');

        $other = $this->createTenant(['country' => 'AE']);
        $this->actingAsMember($this->addMember($other));
        $this->postJson('/api/v1/contacts', ['phone' => '0501234567'])->assertCreated()->assertJsonPath('data.phone', '+971501234567');
        $this->postJson('/api/v1/contacts', ['phone' => '0333 1370070'])->assertStatus(422);
    }

    public function test_contact_counts_and_the_source_filter(): void
    {
        $tenant = $this->createTenant();
        $this->actingAsMember($this->addMember($tenant));
        $this->postJson('/api/v1/contacts', ['phone' => '+971501110001', 'name' => 'Sara'])->assertCreated();
        $this->postJson('/api/v1/contacts', ['phone' => '+971501110002', 'name' => 'Omar'])->assertCreated();
        $this->tenantContext()->run($tenant, fn () => Contact::query()->create(['wa_id' => '971501110003', 'source' => 'inbound']));

        $this->getJson('/api/v1/contacts/count')->assertOk()
            ->assertJsonPath('data.total', 3)->assertJsonPath('data.matching', 3)
            ->assertJsonPath('data.sources', [['value' => 'manual', 'count' => 2], ['value' => 'inbound', 'count' => 1]]);
        $this->getJson('/api/v1/contacts/count?q=sara')->assertOk()->assertJsonPath('data.matching', 1)->assertJsonPath('data.total', 3);
        $this->getJson('/api/v1/contacts?source=inbound')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.phone', '+971501110003');
        $this->getJson('/api/v1/contacts/export/count?source=manual')->assertOk()->assertJsonPath('data.count', 2);
    }
}
