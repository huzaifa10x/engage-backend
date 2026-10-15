<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chatbot builder v2:
 * - several live flows per number (keyword / QR / campaign-reply flows beside one default flow);
 *   the "one default flow per number" rule moves to the application (entry router precedence)
 * - flow organisation (folder, archived), scheduling, per-flow editors, review / approval,
 *   incoming-webhook trigger token, subflows (kind = subflow)
 * - per-step "no reply" timers on sessions
 * - node comments, reusable snippets
 * - bot-written internal notes carry the bot's name
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS chatbot_flows_one_live_per_number');
        Schema::table('chatbot_flows', function (Blueprint $table) {
            $table->string('kind', 12)->default('bot');               // bot | subflow
            $table->string('folder', 60)->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampTz('activate_at')->nullable();            // scheduled switch-on
            $table->timestampTz('deactivate_at')->nullable();          // scheduled switch-off
            $table->jsonb('editors')->nullable();                      // membership ids allowed to edit (null = everyone with chatbots.manage)
            $table->string('review_status', 20)->nullable();           // requested | approved | changes_requested
            $table->string('review_hash', 64)->nullable();             // the draft the review was for
            $table->uuid('reviewed_by_membership_id')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->string('webhook_token', 48)->nullable()->unique();
            $table->index(['phone_number_id', 'is_active']);
        });

        Schema::table('chatbot_sessions', function (Blueprint $table) {
            $table->timestampTz('timeout_at')->nullable();             // this step's "no reply after X"
            $table->index(['status', 'timeout_at']);
        });

        Schema::create('chatbot_node_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('flow_id');
            $table->string('node_id', 64);
            $table->uuid('membership_id')->nullable();
            $table->text('body');
            $table->timestampTz('resolved_at')->nullable();
            $table->uuid('resolved_by_membership_id')->nullable();
            $table->timestampsTz();

            $table->index(['flow_id', 'node_id']);
        });
        TenantSchema::enableRls('chatbot_node_comments');
        TenantSchema::tenantForeign('chatbot_node_comments', 'flow_id', 'chatbot_flows', 'CASCADE');

        Schema::create('chatbot_snippets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 80);
            $table->text('body');
            $table->uuid('created_by_membership_id')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'name']);
        });
        TenantSchema::enableRls('chatbot_snippets');

        Schema::table('conversation_notes', function (Blueprint $table) {
            $table->string('author_name', 120)->nullable();            // a chatbot's name when the bot wrote the note
        });

        // Publishing became its own permission: custom roles that could manage chatbots keep publishing.
        app(TenantContext::class)->bypass(fn () => DB::statement(<<<'SQL'
            UPDATE roles SET permissions = permissions || '["chatbots.publish"]'::jsonb
            WHERE permissions @> '["chatbots.manage"]'::jsonb AND NOT permissions @> '["chatbots.publish"]'::jsonb
        SQL));
    }

    public function down(): void
    {
        app(TenantContext::class)->bypass(fn () => DB::statement(<<<'SQL'
            UPDATE roles SET permissions = permissions - 'chatbots.publish'
        SQL));
        Schema::table('conversation_notes', function (Blueprint $table) {
            $table->dropColumn('author_name');
        });
        Schema::dropIfExists('chatbot_snippets');
        Schema::dropIfExists('chatbot_node_comments');
        Schema::table('chatbot_sessions', function (Blueprint $table) {
            $table->dropIndex(['status', 'timeout_at']);
            $table->dropColumn('timeout_at');
        });
        Schema::table('chatbot_flows', function (Blueprint $table) {
            $table->dropIndex(['phone_number_id', 'is_active']);
            $table->dropColumn(['kind', 'folder', 'archived_at', 'activate_at', 'deactivate_at', 'editors', 'review_status', 'review_hash',
                'reviewed_by_membership_id', 'reviewed_at', 'review_note', 'webhook_token']);
        });
        DB::statement('CREATE UNIQUE INDEX chatbot_flows_one_live_per_number ON chatbot_flows (phone_number_id) WHERE is_active');
    }
};
