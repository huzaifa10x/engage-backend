<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Domain\Access\SystemRole;
use App\Domain\Messaging\Models\ConsentEvent;
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
}
