<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Welcome message and ice breakers per number (batch 5, item 3), set on Meta through the
 * Conversational Automation API: {welcome_enabled, welcome_flow_id, ice_breakers: [{text, flow_id}],
 * synced_at}. What each ice breaker or the welcome starts is ours; Meta only shows them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->jsonb('conversational_automation')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('phone_numbers', fn (Blueprint $table) => $table->dropColumn('conversational_automation'));
    }
};
