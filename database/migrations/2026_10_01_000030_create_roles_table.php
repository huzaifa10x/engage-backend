<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // tenant_id NULL = system role (owner/admin/agent/viewer), shared by every tenant.
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('key', 64);
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->jsonb('permissions')->default(DB::raw("'[]'::jsonb"));
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();
        });

        DB::unprepared('CREATE UNIQUE INDEX roles_tenant_key_unique ON roles (tenant_id, key) NULLS NOT DISTINCT');
        TenantSchema::check('roles', 'roles_system_scope_check', '(is_system AND tenant_id IS NULL) OR (NOT is_system AND tenant_id IS NOT NULL)');

        // Read: system roles + own tenant's roles. Write: own tenant only (system roles via bypass/seeder).
        TenantSchema::enableRls(
            'roles',
            using: 'engage_rls_bypass() OR tenant_id IS NULL OR tenant_id = engage_current_tenant()',
            check: 'engage_rls_bypass() OR tenant_id = engage_current_tenant()',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
