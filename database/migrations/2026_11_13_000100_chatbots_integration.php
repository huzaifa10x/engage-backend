<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chatbots & Flows, product integration:
 * - engine level: every flow and published version that exists now keeps engine level 1 (the
 *   behaviour it was published with); new flows get level 2. Nothing a live flow does changes on
 *   deploy.
 * - draft revisions (D1): chatbot_flows.draft_revision is bumped by every saved draft, and a save
 *   or publish based on an older revision is refused (409). The last 50 drafts are kept.
 * - version-aware insights (D3): a fingerprint of every step per published version, so a step's
 *   numbers are only added up across versions in which that step was the same.
 * - runs & activity (D4): chatbot_events.seq orders the events of a run exactly.
 * - start rules (D5): chatbot_flows.start_priority orders the flows on one number.
 * - team handover (D6): inbox teams and conversations.assigned_team_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_flows', function (Blueprint $table) {
            $table->unsignedInteger('draft_revision')->default(1);
            $table->uuid('draft_updated_by_membership_id')->nullable();
            $table->unsignedInteger('start_priority')->default(100);
        });

        DB::statement("SELECT set_config('app.rls_bypass', 'on', true)");
        DB::statement(<<<'SQL'
            UPDATE chatbot_flows SET settings = jsonb_set(COALESCE(settings, '{}'::jsonb), '{engine_level}', '1'::jsonb)
            WHERE NOT jsonb_exists(COALESCE(settings, '{}'::jsonb), 'engine_level')
        SQL);
        DB::statement(<<<'SQL'
            UPDATE chatbot_flow_versions SET settings = jsonb_set(COALESCE(settings, '{}'::jsonb), '{engine_level}', '1'::jsonb)
            WHERE NOT jsonb_exists(COALESCE(settings, '{}'::jsonb), 'engine_level')
        SQL);
        // Today's order on each number (the order routing already used) becomes the explicit priority.
        DB::statement(<<<'SQL'
            UPDATE chatbot_flows f SET start_priority = o.position * 10
            FROM (SELECT id, row_number() OVER (PARTITION BY phone_number_id ORDER BY published_at NULLS LAST, id) AS position FROM chatbot_flows) o
            WHERE o.id = f.id
        SQL);
        DB::statement("SELECT set_config('app.rls_bypass', 'off', true)");

        Schema::create('chatbot_draft_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('flow_id');
            $table->unsignedInteger('revision');
            $table->string('name', 120);
            $table->jsonb('draft');
            $table->jsonb('settings')->nullable();
            $table->uuid('membership_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['flow_id', 'revision']);
        });
        TenantSchema::enableRls('chatbot_draft_revisions');
        TenantSchema::tenantForeign('chatbot_draft_revisions', 'flow_id', 'chatbot_flows', 'CASCADE');

        Schema::table('chatbot_flow_versions', function (Blueprint $table) {
            $table->jsonb('node_hashes')->nullable();
        });

        DB::statement('ALTER TABLE chatbot_events ADD COLUMN seq bigserial');
        Schema::table('chatbot_events', function (Blueprint $table) {
            $table->index(['flow_id', 'flow_version', 'node_id', 'kind']);
            $table->index(['session_id', 'seq']);
        });
        Schema::table('chatbot_sessions', function (Blueprint $table) {
            $table->index(['flow_id', 'created_at']);
        });

        Schema::create('inbox_teams', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 60);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'name']);
        });
        TenantSchema::enableRls('inbox_teams');
        TenantSchema::tenantKey('inbox_teams');

        Schema::create('inbox_team_members', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('team_id');
            $table->uuid('membership_id');

            $table->primary(['team_id', 'membership_id']);
            $table->index('membership_id');
        });
        TenantSchema::enableRls('inbox_team_members');
        TenantSchema::tenantForeign('inbox_team_members', 'team_id', 'inbox_teams', 'CASCADE');
        TenantSchema::tenantForeign('inbox_team_members', 'membership_id', 'tenant_memberships', 'CASCADE');

        Schema::table('conversations', function (Blueprint $table) {
            $table->uuid('assigned_team_id')->nullable();
            $table->index(['tenant_id', 'assigned_team_id']);
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'assigned_team_id']);
            $table->dropColumn('assigned_team_id');
        });
        Schema::dropIfExists('inbox_team_members');
        Schema::dropIfExists('inbox_teams');
        Schema::table('chatbot_sessions', function (Blueprint $table) {
            $table->dropIndex(['flow_id', 'created_at']);
        });
        Schema::table('chatbot_events', function (Blueprint $table) {
            $table->dropIndex(['flow_id', 'flow_version', 'node_id', 'kind']);
            $table->dropIndex(['session_id', 'seq']);
            $table->dropColumn('seq');
        });
        Schema::table('chatbot_flow_versions', function (Blueprint $table) {
            $table->dropColumn('node_hashes');
        });
        Schema::dropIfExists('chatbot_draft_revisions');
        // engine_level stays in the settings JSON: older code ignores it.
        Schema::table('chatbot_flows', function (Blueprint $table) {
            $table->dropColumn(['draft_revision', 'draft_updated_by_membership_id', 'start_priority']);
        });
    }
};
