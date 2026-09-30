<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Proves isolation at the DATABASE layer — raw queries that bypass Eloquent entirely.
 */
final class RowLevelSecurityTest extends TestCase
{
    public function test_every_table_with_tenant_id_has_forced_rls(): void
    {
        $tables = DB::select(<<<'SQL'
            SELECT c.relname, c.relrowsecurity, c.relforcerowsecurity
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'tenant_id' AND NOT a.attisdropped
            WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p') AND NOT c.relispartition
        SQL);

        $this->assertNotEmpty($tables);

        foreach ($tables as $table) {
            $this->assertTrue($table->relrowsecurity, "RLS not enabled on {$table->relname}");
            $this->assertTrue($table->relforcerowsecurity, "RLS not FORCED on {$table->relname}");
        }
    }

    public function test_no_context_means_no_rows(): void
    {
        $a = $this->createTenant();
        $this->addMember($a);

        $this->tenantContext()->clear();

        $this->assertSame(0, DB::table('tenant_memberships')->count());
        $this->assertSame(0, DB::table('tenants')->count());
    }

    public function test_rows_are_limited_to_the_active_tenant(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();
        $this->addMember($a);
        $this->addMember($a);
        $this->addMember($b);

        $this->tenantContext()->set($a);

        $this->assertSame([$a->id], DB::table('tenant_memberships')->distinct()->pluck('tenant_id')->all());
        $this->assertSame(2, DB::table('tenant_memberships')->count());
        $this->assertSame(0, DB::table('tenant_memberships')->where('tenant_id', $b->id)->count());
    }

    public function test_insert_into_another_tenant_is_rejected_by_with_check(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();
        $owner = $this->addMember($a);

        $this->tenantContext()->set($a);

        $this->expectException(QueryException::class);

        DB::table('invitations')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $b->id,
            'email' => 'x@example.com',
            'role_id' => $owner->role_id,
            'token_hash' => hash('sha256', 'x'),
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_user_sees_own_memberships_across_tenants_without_tenant_context(): void
    {
        $a = $this->createTenant();
        $b = $this->createTenant();
        $mine = $this->addMember($a);
        $this->addMember($b, user: $mine->user);
        $this->addMember($b); // someone else

        $this->tenantContext()->clear();
        $this->tenantContext()->setUser($mine->user_id);

        $this->assertSame(2, DB::table('tenant_memberships')->count());
        $this->assertSame(2, DB::table('tenants')->count());
    }

    public function test_bypass_sees_all_tenants(): void
    {
        $this->addMember($this->createTenant());
        $this->addMember($this->createTenant());

        $this->assertSame(2, $this->tenantContext()->bypass(fn () => DB::table('tenant_memberships')->count()));
    }
}
