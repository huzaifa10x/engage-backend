<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consent capture and campaign guardrails (batch 4, item 4).
 *
 *  - consent_events: the channel and the exact words the customer was shown.
 *  - campaigns: "Send only to opted-in contacts", and the sender's confirmation for contacts with no
 *    consent record (who, when, how many).
 *  - campaign_recipients: the contact's marketing consent when the campaign was prepared (for results
 *    split by consent).
 *  - growth_click_events: the website's marketing-consent checkbox, ticked or not, and its words.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consent_events', function (Blueprint $table) {
            $table->string('channel', 16)->default('whatsapp');
            $table->text('wording')->nullable();
        });
        Schema::table('campaigns', function (Blueprint $table) {
            $table->boolean('opted_in_only')->default(false);
            $table->jsonb('consent_confirmation')->nullable();
        });
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->string('consent_state', 16)->nullable();
        });
        Schema::table('growth_click_events', function (Blueprint $table) {
            $table->boolean('marketing_consent')->nullable();
            $table->string('marketing_consent_text', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('growth_click_events', fn (Blueprint $t) => $t->dropColumn(['marketing_consent', 'marketing_consent_text']));
        Schema::table('campaign_recipients', fn (Blueprint $t) => $t->dropColumn('consent_state'));
        Schema::table('campaigns', fn (Blueprint $t) => $t->dropColumn(['opted_in_only', 'consent_confirmation']));
        Schema::table('consent_events', fn (Blueprint $t) => $t->dropColumn(['channel', 'wording']));
    }
};
