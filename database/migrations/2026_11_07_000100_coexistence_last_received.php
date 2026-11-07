<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When WhatsApp last sent a chunk for a sync (not when we last imported one): lets the import show
 * "paused, WhatsApp stopped sending" instead of a spinner, and close it once Meta's window is over.
 * Filled for running syncs from the webhooks already received.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coexistence_sync_jobs', fn (Blueprint $table) => $table->timestampTz('last_received_at')->nullable());

        DB::statement("SELECT set_config('app.rls_bypass', 'on', true)");
        DB::statement(<<<'SQL'
            UPDATE coexistence_sync_jobs j SET last_received_at = (
                SELECT max(w.received_at) FROM webhook_inbound_log w
                JOIN phone_numbers p ON p.phone_number_id = w.phone_number_id
                WHERE p.id = j.phone_number_id AND w.field = j.sync_type AND w.received_at > now() - interval '30 days'
            )
        SQL);
        DB::statement("SELECT set_config('app.rls_bypass', 'off', true)");
    }

    public function down(): void
    {
        Schema::table('coexistence_sync_jobs', fn (Blueprint $table) => $table->dropColumn('last_received_at'));
    }
};
