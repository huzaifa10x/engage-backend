<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The installed phone app (mobile brief): the one phone a person's app is signed in on. Signing in to
 * the app on another phone moves it there, which ends the app session on the old phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('app_device_id', 64)->nullable();
            $table->timestampTz('app_device_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['app_device_id', 'app_device_at']);
        });
    }
};
