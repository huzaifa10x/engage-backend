<?php

declare(strict_types=1);

namespace Database\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Migration helpers for tenant-owned tables. Every table with a tenant_id column MUST call
 * enableRls() in its migration — CI's RowLevelSecurityTest asserts it.
 */
final class TenantSchema
{
    public const DEFAULT_POLICY = 'engage_rls_bypass() OR tenant_id = engage_current_tenant()';

    /**
     * ENABLE + FORCE row level security (FORCE makes it apply to the table owner too, which is
     * the application role) and create the tenant_isolation policy.
     */
    public static function enableRls(string $table, ?string $using = null, ?string $check = null): void
    {
        self::assertIdentifier($table);

        $using ??= self::DEFAULT_POLICY;
        $check ??= $using;

        DB::unprepared(<<<SQL
            ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
            ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS tenant_isolation ON {$table};
            CREATE POLICY tenant_isolation ON {$table} USING ({$using}) WITH CHECK ({$check});
        SQL);
    }

    /** UNIQUE (tenant_id, id) — the target of composite tenant foreign keys. */
    public static function tenantKey(string $table): void
    {
        self::assertIdentifier($table);

        DB::unprepared("ALTER TABLE {$table} ADD CONSTRAINT {$table}_tenant_id_id_unique UNIQUE (tenant_id, id)");
    }

    /**
     * FOREIGN KEY (tenant_id, $column) REFERENCES $references (tenant_id, id): a child row can
     * never point at a parent owned by another tenant, even if application code is wrong.
     * Only RESTRICT / CASCADE are allowed — SET NULL would also null tenant_id.
     */
    public static function tenantForeign(string $table, string $column, string $references, string $onDelete = 'RESTRICT'): void
    {
        foreach ([$table, $column, $references] as $identifier) {
            self::assertIdentifier($identifier);
        }

        if (! in_array($onDelete, ['RESTRICT', 'CASCADE', 'NO ACTION'], true)) {
            throw new InvalidArgumentException('Composite tenant FKs support RESTRICT, CASCADE or NO ACTION only.');
        }

        DB::unprepared(sprintf(
            'ALTER TABLE %1$s ADD CONSTRAINT %1$s_%2$s_tenant_fk FOREIGN KEY (tenant_id, %2$s) REFERENCES %3$s (tenant_id, id) ON DELETE %4$s',
            $table, $column, $references, $onDelete,
        ));
    }

    public static function check(string $table, string $name, string $expression): void
    {
        self::assertIdentifier($table);
        self::assertIdentifier($name);

        DB::unprepared("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $identifier) !== 1) {
            throw new InvalidArgumentException("Invalid identifier [{$identifier}].");
        }
    }
}
