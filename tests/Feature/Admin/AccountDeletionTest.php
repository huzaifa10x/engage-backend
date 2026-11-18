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

    public function test_deleting_a_member_who_owns_no_company_removes_only_that_user(): void
    {
        $tenant = $this->createTenant();
        $owner = $this->addMember($tenant);
        $agent = $this->addMember($tenant, SystemRole::Agent);
        $agentUser = User::query()->findOrFail($agent->user_id);

        $this->actingAsAdmin();
        $this->get("/admin/users/{$agentUser->id}")->assertOk()->assertInertia(fn ($page) => $page->where('owned_companies', []));
        $this->delete("/admin/users/{$agentUser->id}", ['confirm' => 'nope@example.com', 'reason' => 'Left the company'])->assertSessionHasErrors('confirm');
        $this->delete("/admin/users/{$agentUser->id}", ['confirm' => strtoupper((string) $agentUser->email), 'reason' => 'Left the company'])->assertRedirect('/admin/users');

        $this->assertNull(User::query()->find($agentUser->id));
        $this->assertFalse($this->tenantContext()->bypass(fn () => TenantMembership::query()->whereKey($agent->id)->exists()));
        // The company, its Owner and their membership are untouched.
        $this->assertNotNull($this->tenantContext()->bypass(fn () => Tenant::query()->find($tenant->id)));
        $this->assertSame('active', $this->tenantContext()->bypass(fn () => Tenant::query()->find($tenant->id)?->status->value));
        $this->assertNotNull(User::query()->find($owner->user_id));
        $this->assertTrue($this->tenantContext()->bypass(fn () => TenantMembership::query()->whereKey($owner->id)->exists()));
        $audit = $this->platformAudit('user.deleted')?->getAttribute('meta');
        $this->assertSame([$agentUser->email, []], [$audit['email'] ?? null, $audit['companies_deleted'] ?? null]);
    }

    public function test_deleting_the_owner_deletes_the_company_they_own_and_keeps_shared_ones_and_other_people(): void
    {
        $this->configureMeta();
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        // Their own company, with an agent and data.
        $owned = $this->createTenant(['name' => 'Fast Wheels']);
        $this->subscribe($owned, 'pro');
        $owner = $this->addMember($owned);
        $ownerUser = User::query()->findOrFail($owner->user_id);
        $agent = $this->addMember($owned, SystemRole::Agent);
        $this->connectNumber($owned);
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue()))->assertOk();
        // A company with another Owner: kept, only this user's membership goes.
        $shared = $this->createTenant(['name' => 'Partners Co']);
        $partner = $this->addMember($shared);
        $this->addMember($shared, SystemRole::Owner, $ownerUser);
        // Unrelated company.
        $other = $this->createTenant(['name' => 'Someone Else']);
        $this->addMember($other);

        $this->actingAsAdmin();
        $this->get("/admin/users/{$ownerUser->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('owned_companies', [['id' => $owned->id, 'name' => 'Fast Wheels', 'members' => 1]]));
        $this->delete("/admin/users/{$ownerUser->id}", ['confirm' => $ownerUser->email, 'reason' => 'Client closed their account'])->assertRedirect('/admin/users');

        // The user and their company are gone, with every row of it (the queue runs synchronously in tests).
        $this->assertNull(User::query()->find($ownerUser->id));
        $this->assertNull($this->tenantContext()->bypass(fn () => Tenant::query()->withTrashed()->find($owned->id)));
        foreach (PurgeTenant::tablesInDeleteOrder() as $table) {
            $this->assertSame(0, $this->tenantContext()->bypass(fn () => DB::table($table)->where('tenant_id', $owned->id)->count()), "{$table} still has rows");
        }
        // Its agent keeps their own account (without that workspace).
        $this->assertNotNull(User::query()->find($agent->user_id));
        // The shared company and its other Owner stay; the unrelated company is untouched.
        $this->assertSame('active', $this->tenantContext()->bypass(fn () => Tenant::query()->find($shared->id)?->status->value));
        $this->assertTrue($this->tenantContext()->bypass(fn () => TenantMembership::query()->whereKey($partner->id)->exists()));
        $this->assertSame(1, $this->tenantContext()->bypass(fn () => TenantMembership::query()->where('tenant_id', $shared->id)->count()));
        $this->assertNotNull($this->tenantContext()->bypass(fn () => Tenant::query()->find($other->id)));

        $audit = $this->platformAudit('user.deleted')?->getAttribute('meta');
        $this->assertSame([['id' => $owned->id, 'name' => 'Fast Wheels']], $audit['companies_deleted'] ?? null);
        $this->assertSame('Client closed their account', $this->platformAudit('tenant.deleted')?->getAttribute('meta')['reason'] ?? null);
    }

    public function test_nothing_is_deleted_when_a_company_they_own_cannot_be(): void
    {
        $owned = $this->createTenant(['name' => 'Review Co']);
        $owner = $this->addMember($owned);
        $ownerUser = User::query()->findOrFail($owner->user_id);
        $reviewer = $this->addMember($owned, SystemRole::Agent);
        User::query()->findOrFail($reviewer->user_id)->forceFill(['review_account_at' => now()])->save();

        $this->actingAsAdmin();
        $this->delete("/admin/users/{$ownerUser->id}", ['confirm' => $ownerUser->email, 'reason' => 'Client closed their account'])
            ->assertSessionHasErrors(['confirm' => 'Review Co holds the Meta review account. End the review account first (Users › the account › End review account).']);
        $this->assertNotNull(User::query()->find($ownerUser->id));
        $this->assertSame('active', $this->tenantContext()->bypass(fn () => Tenant::query()->find($owned->id)?->status->value));
    }

    public function test_companies_left_without_an_owner_are_listed_and_can_be_deleted(): void
    {
        // As before this change: the owner's account was deleted and the company stayed behind.
        $orphan = $this->createTenant(['name' => 'Left Behind']);
        $owner = $this->addMember($orphan);
        $this->addMember($orphan, SystemRole::Agent);
        $this->tenantContext()->bypass(fn () => DB::table('tenant_memberships')->where('id', $owner->id)->delete());
        $fine = $this->createTenant(['name' => 'Has Owner']);
        $this->addMember($fine);

        $this->artisan('engage:companies:without-owner')->expectsOutputToContain('Left Behind')->doesntExpectOutputToContain('Has Owner')->assertSuccessful();
        $this->assertSame('active', $this->tenantContext()->bypass(fn () => Tenant::query()->find($orphan->id)?->status->value), 'preview changes nothing');
        $this->artisan('engage:companies:without-owner', ['--delete' => true])->assertFailed(); // a reason is required
        $this->artisan('engage:companies:without-owner', ['--delete' => true, '--reason' => 'Owner account deleted earlier'])
            ->expectsConfirmation('Permanently delete these 1 companies and all their data?', 'yes')->assertSuccessful();

        $this->assertNull($this->tenantContext()->bypass(fn () => Tenant::query()->withTrashed()->find($orphan->id)));
        $this->assertNotNull($this->tenantContext()->bypass(fn () => Tenant::query()->find($fine->id)));
        $this->artisan('engage:companies:without-owner')->expectsOutputToContain('Every company has an active Owner.')->assertSuccessful();
    }

    public function test_only_a_super_admin_can_delete(): void
    {
        $tenant = $this->createTenant();
        $this->actingAsAdmin(PlatformRole::Operations);
        $this->delete("/admin/companies/{$tenant->id}", ['confirm' => $tenant->name, 'reason' => 'Not allowed'])->assertForbidden();
    }
}
