<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A/B testing on campaigns (batch 5, item 1).
 *
 *  - campaigns.ab_test: what is tested (template text, button or send time), the goal, the minimum
 *    sample per variant, the share of the list in the test (100% or 20%), variant B's template, and
 *    — once sent — which variant went to the rest of the list.
 *  - campaign_recipients.variant / phase: A or B, and test or rest (the 80% held back for the winner).
 *    "held" recipients wait for the winner; not_before delays variant B in a send-time test.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->jsonb('ab_test')->nullable();
        });
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->char('variant', 1)->nullable();
            $table->string('phase', 6)->nullable();
            $table->timestampTz('not_before')->nullable();
        });
        DB::statement('ALTER TABLE campaign_recipients DROP CONSTRAINT campaign_recipients_status_check');
        TenantSchema::check('campaign_recipients', 'campaign_recipients_status_check', "status IN ('pending','queued','skipped','failed','held')");
        TenantSchema::check('campaign_recipients', 'campaign_recipients_variant_check', "variant IS NULL OR variant IN ('A','B')");
        DB::statement('CREATE INDEX campaign_recipients_ab ON campaign_recipients (campaign_id, phase, variant) WHERE phase IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS campaign_recipients_ab');
        DB::statement('ALTER TABLE campaign_recipients DROP CONSTRAINT campaign_recipients_variant_check');
        DB::statement("UPDATE campaign_recipients SET status = 'skipped', reason = 'Campaign stopped' WHERE status = 'held'");
        DB::statement('ALTER TABLE campaign_recipients DROP CONSTRAINT campaign_recipients_status_check');
        TenantSchema::check('campaign_recipients', 'campaign_recipients_status_check', "status IN ('pending','queued','skipped','failed')");
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropColumn(['variant', 'phase', 'not_before']);
        });
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('ab_test');
        });
    }
};
