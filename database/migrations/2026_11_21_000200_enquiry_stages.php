<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 6, item 4: lead stages live on the enquiry.
 *
 *  - every stage belongs to a group (open, won, lost), so reports work whatever the names;
 *  - the enquiry keeps its stage, a version number (no overwrites) and the lost reason;
 *  - each logged change records its enquiry, the new version and the reason / reference.
 * Existing stages called "Won" or "Lost" join those groups; every other stage is Open.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_stages', function (Blueprint $table) {
            $table->string('group', 5)->default('open');
        });
        DB::statement("ALTER TABLE crm_stages ADD CONSTRAINT crm_stages_group_check CHECK (\"group\" IN ('open','won','lost'))");
        DB::statement("UPDATE crm_stages SET \"group\" = 'won' WHERE lower(trim(name)) = 'won'");
        DB::statement("UPDATE crm_stages SET \"group\" = 'lost' WHERE lower(trim(name)) = 'lost'");

        Schema::table('enquiries', function (Blueprint $table) {
            $table->integer('stage_version')->default(0);
            $table->string('lost_reason', 200)->nullable();
            $table->timestampTz('stage_changed_at')->nullable();
        });

        Schema::table('contact_stage_events', function (Blueprint $table) {
            $table->uuid('enquiry_id')->nullable();
            $table->integer('version')->nullable();
            $table->string('reason', 200)->nullable();   // lost reason, Won approval reference, or what moved it
            $table->index(['tenant_id', 'enquiry_id']);
        });
        // Who or what moved it: also a flow step, a booking, a verified payment, the lead rule.
        DB::statement('ALTER TABLE contact_stage_events DROP CONSTRAINT IF EXISTS contact_stage_events_source_check');
        DB::statement("ALTER TABLE contact_stage_events ADD CONSTRAINT contact_stage_events_source_check CHECK (source IN ('user','api','import','automation','flow','booking','payment','lead_rule'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE contact_stage_events DROP CONSTRAINT IF EXISTS contact_stage_events_source_check');
        DB::statement("ALTER TABLE contact_stage_events ADD CONSTRAINT contact_stage_events_source_check CHECK (source IN ('user','api','import','automation'))");
        Schema::table('contact_stage_events', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'enquiry_id']);
            $table->dropColumn(['enquiry_id', 'version', 'reason']);
        });
        Schema::table('enquiries', fn (Blueprint $table) => $table->dropColumn(['stage_version', 'lost_reason', 'stage_changed_at']));
        DB::statement('ALTER TABLE crm_stages DROP CONSTRAINT IF EXISTS crm_stages_group_check');
        Schema::table('crm_stages', fn (Blueprint $table) => $table->dropColumn('group'));
    }
};
