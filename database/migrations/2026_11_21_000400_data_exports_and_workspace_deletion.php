<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 6, item 14. Data exports the Owner asks for (CSV files made in the background, kept 7 days),
 * and a workspace deletion the Owner schedules: it happens after the waiting period unless cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('data_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('kind', 32);
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('rows')->nullable();
            $table->string('disk', 32)->nullable();
            $table->string('path', 300)->nullable();
            $table->string('error', 300)->nullable();
            $table->uuid('requested_by_membership_id')->nullable();
            $table->string('requested_by_name', 120)->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'created_at']);
        });
        TenantSchema::check('data_exports', 'data_exports_status_check', "status IN ('queued','running','ready','failed')");
        TenantSchema::enableRls('data_exports');

        Schema::table('tenants', function (Blueprint $table) {
            $table->timestampTz('deletion_requested_at')->nullable();
            $table->timestampTz('deletion_scheduled_for')->nullable()->index();
            $table->uuid('deletion_requested_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['deletion_requested_at', 'deletion_scheduled_for', 'deletion_requested_by']);
        });
        Schema::dropIfExists('data_exports');
    }
};
