<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Batch 6, item 5: the delivery log shows every attempt (when, the answer, how long it took). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_endpoint_deliveries', function (Blueprint $table) {
            $table->jsonb('attempt_log')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('webhook_endpoint_deliveries', function (Blueprint $table) {
            $table->dropColumn('attempt_log');
        });
    }
};
