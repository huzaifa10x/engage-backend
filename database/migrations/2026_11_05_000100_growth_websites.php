<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Growth Hub R1, website half (Developer Handover steps 5–10): the columns the website tracker,
 * the collector and Tracking Setup need on top of the R1 foundation tables.
 *
 *  growth_sites         the public site key (it is in the snippet anyway; the hash stays the lookup),
 *                       the "Open my site" challenge (hash + expiry) and the live-test start time
 *  growth_click_events  the host of the page that sent the visitor (Google organic vs other), the
 *                       page language, the landing page remembered for the tab
 *  growth_site_health   errors today, for the site card
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('growth_sites', function (Blueprint $table) {
            $table->string('site_key', 64)->nullable()->unique();
            $table->string('challenge_hash', 64)->nullable();
            $table->timestampTz('challenge_expires_at')->nullable();
            $table->timestampTz('test_started_at')->nullable();
        });

        Schema::table('growth_click_events', function (Blueprint $table) {
            $table->string('referrer_host', 253)->nullable();
            $table->string('landing_path', 512)->nullable();
            $table->string('language', 8)->nullable();
            $table->index(['tenant_id', 'site_id', 'received_at']);
            $table->index(['status', 'received_at']);
        });

        Schema::table('growth_site_health', function (Blueprint $table) {
            $table->timestampTz('last_error_at')->nullable();
            $table->date('errors_date')->nullable();
            $table->integer('errors_today')->default(0);
        });

        Schema::table('growth_references', function (Blueprint $table) {
            $table->index(['tenant_id', 'destination_phone_number_id']);
        });
    }

    public function down(): void
    {
        Schema::table('growth_references', fn (Blueprint $t) => $t->dropIndex(['tenant_id', 'destination_phone_number_id']));
        Schema::table('growth_site_health', fn (Blueprint $t) => $t->dropColumn(['last_error_at', 'errors_date', 'errors_today']));
        Schema::table('growth_click_events', function (Blueprint $t) {
            $t->dropIndex(['tenant_id', 'site_id', 'received_at']);
            $t->dropIndex(['status', 'received_at']);
            $t->dropColumn(['referrer_host', 'landing_path', 'language']);
        });
        Schema::table('growth_sites', fn (Blueprint $t) => $t->dropColumn(['site_key', 'challenge_hash', 'challenge_expires_at', 'test_started_at']));
    }
};
