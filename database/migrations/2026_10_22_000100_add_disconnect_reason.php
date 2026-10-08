<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Why a WhatsApp account or number is disconnected, so the portal can say so and ask for the right fix. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waba_accounts', function (Blueprint $table) {
            // manual | partner_removed | offboarded | access_revoked
            $table->string('disconnect_reason', 32)->nullable();
        });
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->string('disconnect_reason', 32)->nullable();
            $table->timestampTz('disconnected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->dropColumn(['disconnect_reason', 'disconnected_at']);
        });
        Schema::table('waba_accounts', function (Blueprint $table) {
            $table->dropColumn('disconnect_reason');
        });
    }
};
