<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\Models\Role;
use App\Domain\Access\SystemRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Batch 6, item 13: six system roles, custom roles, the new permissions, hidden phone numbers. */
final class RolesAndPermissionsTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private TenantMembership $owner;

    private PhoneNumber $number;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['id' => 'wamid.R1'], '971500000001', 'AE.1', 'One')))->assertOk();
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue(['id' => 'wamid.R2'], '971500000002', 'AE.2', 'Two')))->assertOk();
        $this->actingAsMember($this->owner);
    }

    private function member(SystemRole $role): TenantMembership
    {
        $m = $this->addMember($this->tenant, $role);
        $this->tenantContext()->run($this->tenant, fn () => app(NumberAccess::class)->setGrants($m, [$this->number->id]));

        return $m;
    }

    private function audited(string $action): ?AuditLog
    {
        return $this->tenantContext()->run($this->tenant, fn () => AuditLog::query()->where('action', $action)->latest('created_at')->first());
    }

    public function test_there_are_six_system_roles_and_the_manager_runs_a_team_without_billing_settings_or_api_keys(): void
    {
        $this->assertSame(['admin', 'agent', 'manager', 'marketing', 'owner', 'viewer'],
            collect($this->getJson('/api/v1/roles')->assertOk()->json('data'))->where('is_system', true)->pluck('key')->sort()->values()->all());

        $this->actingAsMember($this->member(SystemRole::Manager));
        $this->assertCount(2, $this->getJson('/api/v1/conversations')->assertOk()->json('data'), 'every chat on their numbers');
        $this->getJson('/api/v1/contacts/export/count')->assertOk();
        $this->getJson('/api/v1/developer/api-keys')->assertForbidden();
        $this->getJson('/api/v1/billing/invoices')->assertForbidden();
        $this->patchJson('/api/v1/tenant', ['name' => 'Mine'])->assertForbidden();
    }

    public function test_an_agent_sees_only_assigned_chats_cannot_export_or_see_api_keys(): void
    {
        $agent = $this->member(SystemRole::Agent);
        $mine = $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->whereHas('contact', fn ($q) => $q->where('wa_id', '971500000001'))->sole());
        $other = $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->whereHas('contact', fn ($q) => $q->where('wa_id', '971500000002'))->sole());
        $this->tenantContext()->run($this->tenant, fn () => $mine->forceFill(['assigned_membership_id' => $agent->id])->save());

        $this->actingAsMember($agent);
        $this->assertSame([$mine->id], collect($this->getJson('/api/v1/conversations')->assertOk()->json('data'))->pluck('id')->all());
        $this->getJson("/api/v1/conversations/{$other->id}")->assertForbidden();
        $this->getJson('/api/v1/contacts/export')->assertForbidden();
        $this->getJson('/api/v1/contacts/export/count')->assertForbidden();
        $this->getJson('/api/v1/developer/api-keys')->assertForbidden();
        $this->getJson('/api/v1/developer/webhooks')->assertForbidden();
    }

    public function test_phone_numbers_can_be_hidden_from_agents_and_the_change_is_audited(): void
    {
        $agent = $this->member(SystemRole::Agent);
        $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->update(['assigned_membership_id' => $agent->id]));

        $this->putJson('/api/v1/team/privacy', ['hide_phone_from_agents' => true])->assertOk()->assertJsonPath('data.hide_phone_from_agents', true);
        $this->assertSame(['hide_phone_from_agents' => true], $this->audited('team.phone_visibility_changed')?->getAttribute('after'));

        $this->actingAsMember($agent);
        $contact = collect($this->getJson('/api/v1/contacts')->assertOk()->json('data'))->firstWhere('display_name', 'One');
        $this->assertSame(['+97150 •••••01', null, true], [$contact['phone'], $contact['wa_id'], $contact['phone_hidden']]);
        $chat = collect($this->getJson('/api/v1/conversations')->assertOk()->json('data'))->first();
        $this->assertStringContainsString('•', (string) $chat['contact']['phone']);
        // They still chat with the contact.
        $this->postJson("/api/v1/conversations/{$chat['id']}/messages", ['type' => 'text', 'body' => 'Hello!'])->assertSuccessful();

        // The Owner, and a Manager, still see the number.
        $this->actingAsMember($this->owner);
        $this->assertSame('+971500000001', collect($this->getJson('/api/v1/contacts')->json('data'))->firstWhere('display_name', 'One')['phone']);
        $this->actingAsMember($this->member(SystemRole::Manager));
        $this->assertSame('+971500000001', collect($this->getJson('/api/v1/contacts')->json('data'))->firstWhere('display_name', 'One')['phone']);

        // Agents see numbers again when the option is off.
        $this->actingAsMember($this->owner);
        $this->putJson('/api/v1/team/privacy', ['hide_phone_from_agents' => false])->assertOk();
        $this->actingAsMember($agent);
        $this->assertSame('+971500000001', collect($this->getJson('/api/v1/contacts')->json('data'))->firstWhere('display_name', 'One')['phone']);
    }

    public function test_custom_roles_are_made_from_permissions_by_area_assigned_and_audited(): void
    {
        $catalog = $this->getJson('/api/v1/roles/catalog')->assertOk();
        $this->assertTrue($catalog->json('data.custom_roles'));
        $keys = collect($catalog->json('data.areas'))->flatMap(fn ($a) => array_column($a['permissions'], 'key'))->all();
        foreach (['stages.change', 'stages.approve_won', 'payments.manual', 'suggestions.approve', 'contacts.view_phone'] as $new) {
            $this->assertContains($new, $keys);
        }

        $this->postJson('/api/v1/roles', ['name' => 'Agent', 'permissions' => []])->assertUnprocessable(); // a system role's name
        $this->postJson('/api/v1/roles', ['name' => 'Desk', 'permissions' => ['inbox.view', 'made.up']])->assertUnprocessable();
        $role = $this->postJson('/api/v1/roles', ['name' => 'Front desk', 'permissions' => ['inbox.view', 'inbox.reply', 'contacts.view']])
            ->assertCreated()->json('data');
        $this->assertSame(['name' => 'Front desk', 'permissions' => ['inbox.view', 'inbox.reply', 'contacts.view']], $this->audited('role.created')?->getAttribute('after'));

        // Given to a teammate: they get exactly those permissions; no phone numbers without "see phone numbers".
        $desk = $this->member(SystemRole::Agent);
        $this->patchJson("/api/v1/team/members/{$desk->id}", ['role_id' => $role['id']])->assertOk();
        $this->assertSame('custom-', substr((string) $this->audited('membership.role_changed')?->getAttribute('after')['role'], 0, 7));
        $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->update(['assigned_membership_id' => $desk->id]));
        $this->actingAsMember($desk);
        $this->getJson('/api/v1/conversations')->assertOk();
        $this->assertStringContainsString('•', (string) collect($this->getJson('/api/v1/contacts')->assertOk()->json('data'))->first()['phone']);
        $this->postJson('/api/v1/contacts', ['phone' => '+971509999999'])->assertForbidden();

        $this->actingAsMember($this->owner);
        $this->putJson("/api/v1/roles/{$role['id']}", ['name' => 'Front desk', 'permissions' => ['inbox.view', 'inbox.reply', 'contacts.view', 'contacts.create']])->assertOk();
        $this->assertSame(['inbox.view', 'inbox.reply', 'contacts.view'], $this->audited('role.updated')?->getAttribute('before')['permissions']);
        $this->actingAsMember($desk);
        $this->postJson('/api/v1/contacts', ['phone' => '+971509999999'])->assertCreated();

        $this->actingAsMember($this->owner);
        $this->deleteJson("/api/v1/roles/{$role['id']}")->assertUnprocessable(); // still given to someone
        $agentRole = Role::system(SystemRole::Agent)->id;
        $this->patchJson("/api/v1/team/members/{$desk->id}", ['role_id' => $agentRole])->assertOk();
        $this->deleteJson("/api/v1/roles/{$role['id']}")->assertNoContent();
        $this->assertSame('Front desk', $this->audited('role.deleted')?->getAttribute('before')['name']);

        $this->putJson("/api/v1/roles/{$agentRole}", ['name' => 'Agent', 'permissions' => []])->assertUnprocessable(); // system roles stay as they are
    }

    public function test_custom_roles_need_a_plan_with_full_role_based_access_and_agents_cannot_make_them(): void
    {
        $starter = $this->createTenant();
        $this->subscribe($starter, 'starter');
        $this->actingAsMember($this->addMember($starter));
        $this->assertFalse($this->getJson('/api/v1/roles/catalog')->assertOk()->json('data.custom_roles'));
        $this->postJson('/api/v1/roles', ['name' => 'Desk', 'permissions' => ['inbox.view']])->assertUnprocessable();

        $this->actingAsMember($this->member(SystemRole::Agent));
        $this->postJson('/api/v1/roles', ['name' => 'Desk', 'permissions' => ['inbox.view']])->assertForbidden();
        $this->putJson('/api/v1/team/privacy', ['hide_phone_from_agents' => true])->assertForbidden();
    }
}
