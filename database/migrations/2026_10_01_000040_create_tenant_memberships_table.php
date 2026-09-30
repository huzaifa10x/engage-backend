<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('role_id')->constrained('roles')->restrictOnDelete();
            $table->string('status', 16)->default('active');
            $table->foreignUuid('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('joined_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'user_id']);
            $table->index('user_id');
            $table->index(['tenant_id', 'role_id']);
        });

        TenantSchema::check('tenant_memberships', 'tenant_memberships_status_check', "status IN ('active','suspended')");
        TenantSchema::tenantKey('tenant_memberships');

        // A user may always see their OWN memberships (workspace switcher) before a tenant is active.
        TenantSchema::enableRls(
            'tenant_memberships',
            using: 'engage_rls_bypass() OR tenant_id = engage_current_tenant() OR user_id = engage_current_user()',
            check: 'engage_rls_bypass() OR tenant_id = engage_current_tenant()',
        );

        // Tenants: visible if active, or if the current user is a member. Writable only when active.
        TenantSchema::enableRls(
            'tenants',
            using: 'engage_rls_bypass() OR id = engage_current_tenant() OR id IN (SELECT m.tenant_id FROM tenant_memberships m WHERE m.user_id = engage_current_user())',
            check: 'engage_rls_bypass() OR id = engage_current_tenant()',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_memberships');
    }
};
