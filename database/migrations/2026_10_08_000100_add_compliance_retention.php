<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retention: old messages are redacted (content removed, the row and its delivery numbers stay)
 * and old media files are deleted ("expired"). Compliance settings live in tenants.settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestampTz('redacted_at')->nullable();
        });

        DB::unprepared('ALTER TABLE media DROP CONSTRAINT IF EXISTS media_status_check');
        TenantSchema::check('media', 'media_status_check', "status IN ('pending','ready','failed','expired')");

        Schema::table('consent_events', function (Blueprint $table) {
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('consent_events', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'created_at']);
        });
        DB::unprepared("UPDATE media SET status = 'failed' WHERE status = 'expired'");
        DB::unprepared('ALTER TABLE media DROP CONSTRAINT IF EXISTS media_status_check');
        TenantSchema::check('media', 'media_status_check', "status IN ('pending','ready','failed')");
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('redacted_at');
        });
    }
};
