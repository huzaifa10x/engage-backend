<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inbox & CRM 3.3, A3 (the pilot kit): when each wait runs out of the reply promise (Needs reply is
 * sorted by it), and the log of who was alerted while a chat waited (30 min agent, 45 Manager, 60 Owner).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestampTz('reply_due_at')->nullable();
            $table->index(['tenant_id', 'reply_due_at']);
        });

        Schema::create('wait_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('conversation_id');
            $table->uuid('contact_id')->nullable();
            $table->timestampTz('episode');                 // when the wait started
            $table->unsignedSmallInteger('level');          // 1, 2, 3 … the ladder step
            $table->unsignedInteger('minutes');
            $table->string('role', 10);                     // agent | manager | owner
            $table->jsonb('recipients');                    // [{id, name}]
            $table->jsonb('channels');                      // ["app", "email", "whatsapp"]
            $table->string('whatsapp_error', 190)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'conversation_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });
        TenantSchema::check('wait_alerts', 'wait_alerts_role_check', "role IN ('agent','manager','owner')");
        TenantSchema::enableRls('wait_alerts');

        // Waits already running get their due time (default limit: 60 business minutes; the sweep
        // recalculates it with each workspace's hours).
        DB::statement("UPDATE conversations SET reply_due_at = needs_reply_since + interval '60 minutes' WHERE reply_state = 'needs_reply' AND needs_reply_since IS NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('wait_alerts');
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'reply_due_at']);
            $table->dropColumn('reply_due_at');
        });
    }
};
