<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Who asked for a flow's review and when: the reviewer's decision is sent back to that person. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chatbot_flows', function (Blueprint $table) {
            $table->uuid('review_requested_by_membership_id')->nullable();
            $table->timestampTz('review_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_flows', fn (Blueprint $table) => $table->dropColumn(['review_requested_by_membership_id', 'review_requested_at']));
    }
};
