<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chatbots & Flows: menu-driven bots that run per WhatsApp number on top of the inbox.
 *
 * - chatbot_flows: one flow is live per number at most; drafts are edited freely, publishing
 *   snapshots the draft into chatbot_flow_versions (rollback restores one of them).
 * - chatbot_sessions: where one customer is in a flow (the version it started on, answers, timers).
 * - chatbot_events: entries / exits per node, for the funnel shown on the canvas.
 * - chatbot_secrets: API keys used by API-call nodes, referenced by name, never stored in a flow.
 * - conversations.handler: who answers right now (bot | human | none). It is authoritative and is
 *   re-checked under the conversation's row lock immediately before every bot message.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_flows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 120);
            $table->uuid('phone_number_id')->nullable();         // the number this bot answers on
            $table->boolean('is_active')->default(false);        // live on that number
            $table->jsonb('draft');                              // {nodes: [...]} being edited
            $table->jsonb('settings')->nullable();               // timers, retries, keywords
            $table->unsignedInteger('published_version')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('draft_updated_at')->nullable();
            $table->uuid('created_by_membership_id')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'phone_number_id']);
        });
        TenantSchema::enableRls('chatbot_flows');
        TenantSchema::tenantKey('chatbot_flows');
        // Exactly one live bot per number: two bots answering the same customer would talk over each other.
        DB::statement('CREATE UNIQUE INDEX chatbot_flows_one_live_per_number ON chatbot_flows (phone_number_id) WHERE is_active');

        Schema::create('chatbot_flow_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('flow_id');
            $table->unsignedInteger('version');
            $table->jsonb('graph');
            $table->jsonb('settings')->nullable();
            $table->uuid('published_by_membership_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['flow_id', 'version']);
        });
        TenantSchema::enableRls('chatbot_flow_versions');
        TenantSchema::tenantForeign('chatbot_flow_versions', 'flow_id', 'chatbot_flows', 'CASCADE');

        Schema::create('chatbot_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('flow_id');
            $table->unsignedInteger('flow_version');
            $table->uuid('conversation_id');
            $table->uuid('contact_id');
            $table->uuid('phone_number_id');
            // active (bot is talking) | waiting (a Delay node) | handed_off | completed | abandoned | stopped
            $table->string('status', 16)->default('active');
            $table->string('current_node', 64)->nullable();
            $table->boolean('awaiting_input')->default(false);
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->jsonb('vars')->nullable();                   // answers, API results, selection maps
            $table->unsignedInteger('steps')->default(0);        // messages sent so far (idempotency keys)
            $table->timestampTz('last_inbound_at')->nullable();  // newest customer message handled (ordering)
            $table->timestampTz('last_activity_at')->nullable();
            $table->timestampTz('reminder_sent_at')->nullable();
            $table->timestampTz('resume_at')->nullable();        // Delay node: when to continue
            $table->string('ended_reason', 40)->nullable();
            $table->string('ended_node', 64)->nullable();        // where the customer left (abandoned)
            $table->timestampTz('ended_at')->nullable();
            $table->timestampsTz();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['status', 'last_activity_at']);
            $table->index(['status', 'resume_at']);
            $table->index(['flow_id', 'status']);
        });
        TenantSchema::enableRls('chatbot_sessions');
        TenantSchema::tenantKey('chatbot_sessions');
        TenantSchema::tenantForeign('chatbot_sessions', 'flow_id', 'chatbot_flows', 'CASCADE');
        TenantSchema::tenantForeign('chatbot_sessions', 'conversation_id', 'conversations', 'CASCADE');
        TenantSchema::check('chatbot_sessions', 'chatbot_sessions_status_check', "status IN ('active','waiting','handed_off','completed','abandoned','stopped')");
        // One running session per conversation, whatever races happen between webhooks.
        DB::statement("CREATE UNIQUE INDEX chatbot_sessions_one_running ON chatbot_sessions (conversation_id) WHERE status IN ('active','waiting')");

        Schema::create('chatbot_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('flow_id');
            $table->unsignedInteger('flow_version');
            $table->uuid('session_id');
            $table->string('node_id', 64);
            $table->string('kind', 16);                          // enter | click | abandon | complete | handoff | error
            $table->string('detail', 120)->nullable();           // the output taken, or why it failed
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['flow_id', 'created_at']);
        });
        TenantSchema::enableRls('chatbot_events');
        TenantSchema::tenantForeign('chatbot_events', 'flow_id', 'chatbot_flows', 'CASCADE');

        Schema::create('chatbot_secrets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 60);                          // referenced in nodes as {{secret.NAME}}
            $table->uuid('secret_id');                           // value, encrypted in the secret store
            $table->string('hint', 8)->nullable();               // last characters, to recognise it
            $table->timestampsTz();

            $table->unique(['tenant_id', 'name']);
        });
        TenantSchema::enableRls('chatbot_secrets');

        Schema::table('conversations', function (Blueprint $table) {
            $table->string('handler', 8)->nullable();            // bot | human | null (nobody yet)
            $table->timestampTz('handler_changed_at')->nullable();
            $table->uuid('bot_session_id')->nullable();
            // Last bot outcome, for the inbox filters: running | handed_off | completed | abandoned | stopped
            $table->string('bot_state', 16)->nullable();
            $table->index(['tenant_id', 'handler']);
            $table->index(['tenant_id', 'bot_state']);
        });
        TenantSchema::check('conversations', 'conversations_handler_check', "handler IS NULL OR handler IN ('bot','human')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE conversations DROP CONSTRAINT IF EXISTS conversations_handler_check');
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'handler']);
            $table->dropIndex(['tenant_id', 'bot_state']);
            $table->dropColumn(['handler', 'handler_changed_at', 'bot_session_id', 'bot_state']);
        });
        Schema::dropIfExists('chatbot_secrets');
        Schema::dropIfExists('chatbot_events');
        Schema::dropIfExists('chatbot_sessions');
        Schema::dropIfExists('chatbot_flow_versions');
        Schema::dropIfExists('chatbot_flows');
    }
};
