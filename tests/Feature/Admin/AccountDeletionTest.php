<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Access\SystemRole;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Enums\PlatformRole;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Jobs\PurgeTenant;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Super Admin › delete a company (erases all of its data) or a user. */
final class AccountDeletionTest extends TestCase
{
    use InteractsWithWhatsapp;

    private function platformAudit(string $action): ?AuditLog
    {
        return $this->tenantContext()->bypass(fn () => AuditLog::query()->where('action', $action)->latest('created_at')->first());
    }

    public function test_deleting_a_company_erases_every_row_it_owns_and_keeps_a_record(): void
    {
        $this->configureMeta();
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $tenant = $this->createTenant();
        $this->subscribe($tenant, 'pro');
        $owner = $this->addMember($tenant);
        $this->connectNumber($tenant);
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue()))->assertOk();
        $keep = $this->createTenant();
        $this->addMember($keep);
        $this->assertSame(1, $this->tenantContext()->run($tenant, fn () => Message::query()->count()));

        $this->actingAsAdmin();
        $this->delete("/admin/companies/{$tenant->id}", ['confirm' => 'wrong', 'reason' => 'Client asked to close'])->assertSessionHasErrors('confirm');
        $this->delete("/admin/companies/{$tenant->id}", ['confirm' => $tenant->name, 'reason' => 'Client asked to close'])->assertRedirect('/admin/companies');

        // Gone everywhere (the queue runs synchronously in tests); the other company is untouched.
        foreach (PurgeTenant::tablesInDeleteOrder() as $table) {
            $left = $this->tenantContext()->bypass(fn () => DB::table($table)->where('tenant_id', $tenant->id)->count());
            $this->assertSame(0, $left, "{$table} still has rows");
        }
        $this->assertNull($this->tenantContext()->bypass(fn () => Tenant::query()->withTrashed()->find($tenant->id)));
        $this->assertNotNull($this->tenantContext()->bypass(fn () => Tenant::query()->find($keep->id)));
        $this->assertNotNull(User::query()->find($owner->user_id)); // the person stays, without the workspace
        $this->assertSame('Client asked to close', $this->platformAudit('tenant.deleted')?->getAttribute('meta')['reason'] ?? null);
    }

    public function test_deleting_a_user_and_the_only_owner_rule(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->addMember($tenant);
        $agent = $this->addMember($tenant, SystemRole::Agent);
        $ownerUser = User::query()->findOrFail($owner->user_id);
        $agentUser = User::query()->findOrFail($agent->user_id);

        $this->actingAsAdmin();
        // The only Owner of a workspace with other members cannot be deleted.
        $this->delete("/admin/users/{$ownerUser->id}", ['confirm' => $ownerUser->email, 'reason' => 'Left the company'])->assertSessionHasErrors('confirm');

        $this->delete("/admin/users/{$agentUser->id}", ['confirm' => 'nope@example.com', 'reason' => 'Left the company'])->assertSessionHasErrors('confirm');
        $this->delete("/admin/users/{$agentUser->id}", ['confirm' => strtoupper((string) $agentUser->email), 'reason' => 'Left the company'])->assertRedirect('/admin/users');
        $this->assertNull(User::query()->find($agentUser->id));
        $this->assertFalse($this->tenantContext()->bypass(fn () => TenantMembership::query()->whereKey($agent->id)->exists()));
        $this->assertSame($agentUser->email, $this->platformAudit('user.deleted')?->getAttribute('meta')['email'] ?? null);
    }

    public function test_only_a_super_admin_can_delete(): void
    {
        $tenant = $this->createTenant();
        $this->actingAsAdmin(PlatformRole::Operations);
        $this->delete("/admin/companies/{$tenant->id}", ['confirm' => $tenant->name, 'reason' => 'Not allowed'])->assertForbidden();
    }
}
