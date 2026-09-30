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
        // TOTP replay protection: a code's 30-second step can be used once.
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->bigInteger('two_factor_last_used_step')->nullable()->after('two_factor_confirmed_at');
        });

        // Monthly metered features (campaign reach, automation executions).
        DB::unprepared('ALTER TABLE features DROP CONSTRAINT features_type_check');
        TenantSchema::check('features', 'features_type_check', "type IN ('boolean','limit','metered')");

        /*
         * Audited, time-boxed "log in as" sessions. A one-time token is handed to the Next.js
         * client, which exchanges it for a normal session flagged as an impersonation.
         */
        Schema::create('impersonation_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('platform_admin_id')->constrained('platform_admins')->restrictOnDelete();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('token_expires_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'created_at']);
        });

        // Platform-only table: tenants never read it (the audit log is their record).
        TenantSchema::enableRls('impersonation_sessions', using: 'engage_rls_bypass()', check: 'engage_rls_bypass()');
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_sessions');
        DB::unprepared('ALTER TABLE features DROP CONSTRAINT features_type_check');
        TenantSchema::check('features', 'features_type_check', "type IN ('boolean','limit')");
        Schema::table('platform_admins', fn (Blueprint $table) => $table->dropColumn('two_factor_last_used_step'));
    }
};
