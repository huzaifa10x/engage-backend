<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When Meta accepted an outgoing message (our send call returned its ID). With the webhook's receipt
 * time this measures how long a customer waits for an automatic reply (engage:webhooks:latency).
 * A nullable column with no default: added instantly, no table rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestampTz('accepted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('accepted_at');
        });
    }
};
