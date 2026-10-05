<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign governance: notes / objective, an ad-hoc tag audience (CSV uploads), drip sending,
 * and the `paused` state (manual, or automatic when the number's quality drops).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->text('notes')->nullable();
            $table->string('objective', 24)->nullable();             // promo | announcement | re_engagement | reminder | update | other
            $table->string('audience_tag', 40)->nullable();          // narrows the audience to one tag (e.g. a CSV upload)
            $table->unsignedInteger('batch_per_hour')->nullable();   // drip: at most N messages per hour (null = as fast as allowed)
            $table->timestampTz('next_batch_at')->nullable();        // when sending continues (quiet hours / drip)
            $table->timestampTz('paused_at')->nullable();
            $table->string('pause_reason', 190)->nullable();
        });

        DB::unprepared('ALTER TABLE campaigns DROP CONSTRAINT IF EXISTS campaigns_status_check');
        TenantSchema::check('campaigns', 'campaigns_status_check', "status IN ('draft','scheduled','sending','paused','completed','cancelled','failed')");

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->index(['contact_id', 'created_at']); // frequency cap: "how many did this contact get lately"
        });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropIndex(['contact_id', 'created_at']);
        });
        DB::unprepared("UPDATE campaigns SET status = 'cancelled' WHERE status = 'paused'");
        DB::unprepared('ALTER TABLE campaigns DROP CONSTRAINT IF EXISTS campaigns_status_check');
        TenantSchema::check('campaigns', 'campaigns_status_check', "status IN ('draft','scheduled','sending','completed','cancelled','failed')");
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['notes', 'objective', 'audience_tag', 'batch_per_hour', 'next_batch_at', 'paused_at', 'pause_reason']);
        });
    }
};
