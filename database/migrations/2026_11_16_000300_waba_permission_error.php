<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When Meta last refused a WhatsApp account for a missing permission (Graph error 200–299 or 10):
 * Channels shows "Reconnect" until a later call to Meta for that account succeeds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waba_accounts', function (Blueprint $table) {
            $table->timestampTz('permission_error_at')->nullable();
            $table->jsonb('permission_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('waba_accounts', fn (Blueprint $table) => $table->dropColumn(['permission_error_at', 'permission_error']));
    }
};
