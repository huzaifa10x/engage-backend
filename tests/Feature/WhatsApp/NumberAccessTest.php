<?php

declare(strict_types=1);

namespace Tests\Feature\WhatsApp;

use App\Domain\Access\SystemRole;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

final class NumberAccessTest extends TestCase
{
    use InteractsWithWhatsapp;

    public function test_agents_only_see_granted_numbers_and_owners_see_all(): void
    {
        $tenant = $this->createTenant();
        $this->subscribe($tenant, 'pro');
        $owner = $this->addMember($tenant);
        $agent = $this->addMember($tenant, SystemRole::Agent);
        $sales = $this->connectNumber($tenant, '500000000001', '500000000011', '+971 50 000 0011');
        $support = $this->connectNumber($tenant, '500000000002', '500000000022', '+971 50 000 0022');

        $this->actingAsMember($agent)->getJson('/api/v1/phone-numbers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/phone-numbers/{$sales->id}")->assertForbidden()->assertJsonPath('error.code', 'number_access_denied');

        $this->actingAsMember($owner)->putJson("/api/v1/team/members/{$agent->id}/numbers", ['phone_number_ids' => [$sales->id]])
            ->assertOk()->assertJsonPath('data.all_numbers', false)->assertJsonPath('data.phone_number_ids', [$sales->id]);
        $this->getJson('/api/v1/phone-numbers')->assertJsonCount(2, 'data');

        $this->actingAsMember($agent)->getJson('/api/v1/phone-numbers')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sales->id);
        $this->getJson("/api/v1/phone-numbers/{$support->id}")->assertForbidden();
    }

    public function test_grants_cannot_reference_another_workspaces_number(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->addMember($tenant);
        $agent = $this->addMember($tenant, SystemRole::Agent);
        $foreign = $this->connectNumber($this->createTenant());

        $this->actingAsMember($owner)->putJson("/api/v1/team/members/{$agent->id}/numbers", ['phone_number_ids' => [$foreign->id]])
            ->assertStatus(422);
    }
}
