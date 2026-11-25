<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 6 (billing through Stripe-hosted pages): a downgrade or Annual → Monthly switch made in the
 * Customer Portal is scheduled for the end of the period. The subscription mirrors that pending change
 * so the billing page can show "Moves to Starter · Monthly on 1 Dec".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignUuid('scheduled_plan_version_id')->nullable()->constrained('plan_versions')->nullOnDelete();
            $table->string('scheduled_interval', 8)->nullable();      // monthly | yearly
            $table->timestampTz('scheduled_change_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('scheduled_plan_version_id');
            $table->dropColumn(['scheduled_interval', 'scheduled_change_at']);
        });
    }
};
