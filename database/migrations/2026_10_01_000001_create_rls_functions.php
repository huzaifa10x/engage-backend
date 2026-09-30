<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Helpers read by every RLS policy. Values are set per connection by
     * App\Domain\Tenancy\Database\PostgresSessionVariables. An unset / empty value yields NULL,
     * and `tenant_id = NULL` is never true — so no context means no rows (fail closed).
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION engage_current_tenant() RETURNS uuid
                LANGUAGE sql STABLE PARALLEL SAFE
                AS $$ SELECT NULLIF(current_setting('app.tenant_id', true), '')::uuid $$;

            CREATE OR REPLACE FUNCTION engage_current_user() RETURNS uuid
                LANGUAGE sql STABLE PARALLEL SAFE
                AS $$ SELECT NULLIF(current_setting('app.user_id', true), '')::uuid $$;

            CREATE OR REPLACE FUNCTION engage_rls_bypass() RETURNS boolean
                LANGUAGE sql STABLE PARALLEL SAFE
                AS $$ SELECT COALESCE(current_setting('app.rls_bypass', true), 'off') = 'on' $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS engage_rls_bypass();
            DROP FUNCTION IF EXISTS engage_current_user();
            DROP FUNCTION IF EXISTS engage_current_tenant();
        SQL);
    }
};
