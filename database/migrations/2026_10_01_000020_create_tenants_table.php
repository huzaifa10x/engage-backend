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
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('slug', 64)->unique();
            $table->string('status', 16)->default('active')->index();
            $table->string('timezone', 64)->default('Asia/Dubai');
            $table->string('locale', 10)->default('en');
            $table->char('currency', 3)->default('USD');
            $table->char('country', 2)->nullable();
            $table->string('billing_email', 190)->nullable();
            $table->jsonb('settings')->nullable();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
        });

        TenantSchema::check('tenants', 'tenants_status_check', "status IN ('active','suspended','closed')");

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('last_active_tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });

        // RLS for tenants is created with tenant_memberships (its policy references that table).
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropForeign(['last_active_tenant_id']));
        Schema::dropIfExists('tenants');
    }
};
