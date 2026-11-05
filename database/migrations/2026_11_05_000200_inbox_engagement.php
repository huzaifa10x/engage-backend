<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp Engagement Plan v4, steps A and B.
 *
 *  conversations.reply_state     needs_reply | waiting (null on closed chats); set by the system from
 *                                who wrote last and what the bot did, never by hand
 *  conversations.needs_reply_since  when the customer started waiting (timer, oldest first)
 *  conversations.after_hours     the wait started outside business hours ("Arrived after hours")
 *  conversations.ad_lead         the wait started from a Meta ad / paid campaign (top of the morning queue)
 *  conversations.opened_at       when the chat was last (re)opened (time to resolve)
 *  inbox_response_times          first response and resolution per wait, in business-hours seconds,
 *                                per agent and number (response figures measure people, not bots)
 *  scheduled_actions             the one scheduler for every timed action (snooze returns, the
 *                                after-hours reply; reply alerts, nudges and store messages next):
 *                                due time, priority, de-duplication key, outcome
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('reply_state', 20)->nullable();
            $table->timestampTz('needs_reply_since')->nullable();
            $table->boolean('after_hours')->default(false);
            $table->boolean('ad_lead')->default(false);
            $table->timestampTz('opened_at')->nullable();
            $table->index(['tenant_id', 'reply_state', 'needs_reply_since']);
        });
        DB::statement("ALTER TABLE conversations ADD CONSTRAINT conversations_reply_state_check CHECK (reply_state IS NULL OR reply_state IN ('needs_reply', 'waiting'))");

        // Existing open chats: the customer wrote last in the past week → Needs reply; otherwise waiting.
        DB::statement(<<<'SQL'
            UPDATE conversations SET
                reply_state = CASE WHEN last_message_direction = 'inbound' AND last_inbound_at >= now() - interval '7 days' THEN 'needs_reply' ELSE 'waiting' END,
                needs_reply_since = CASE WHEN last_message_direction = 'inbound' AND last_inbound_at >= now() - interval '7 days' THEN last_inbound_at END,
                opened_at = coalesce(last_inbound_at, last_message_at, created_at)
            WHERE status = 'open'
        SQL);

        Schema::create('inbox_response_times', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('conversation_id');
            $table->uuid('phone_number_id')->nullable();
            $table->uuid('membership_id')->nullable();   // who answered / closed
            $table->string('kind', 20);                   // first_response | resolution
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at');
            $table->integer('business_seconds');
            $table->boolean('after_hours')->default(false);
            $table->boolean('ad_lead')->default(false);
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'kind', 'ended_at']);
        });

        Schema::create('scheduled_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('kind', 40);
            $table->uuid('conversation_id')->nullable();
            $table->uuid('contact_id')->nullable();
            $table->timestampTz('due_at');
            $table->smallInteger('priority')->default(100);   // lower runs first: after-hours reply 10, nudge 20, store reminder 30
            $table->string('dedupe_key', 191);
            $table->string('status', 20)->default('pending'); // pending | done | cancelled | skipped
            $table->string('result', 60)->nullable();          // why it was skipped / cancelled
            $table->jsonb('payload')->default(DB::raw("'{}'::jsonb"));
            $table->smallInteger('attempts')->default(0);
            $table->boolean('sends_message')->default(false);  // counts against the per-contact frequency cap
            $table->timestampTz('decided_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'dedupe_key']);
            $table->index(['status', 'due_at']);
            $table->index(['tenant_id', 'conversation_id', 'status']);
            $table->index(['tenant_id', 'contact_id', 'decided_at']);
        });

        TenantSchema::enableRls('inbox_response_times');
        TenantSchema::enableRls('scheduled_actions');
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_actions');
        Schema::dropIfExists('inbox_response_times');
        DB::statement('ALTER TABLE conversations DROP CONSTRAINT IF EXISTS conversations_reply_state_check');
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'reply_state', 'needs_reply_since']);
            $table->dropColumn(['reply_state', 'needs_reply_since', 'after_hours', 'ad_lead', 'opened_at']);
        });
    }
};
