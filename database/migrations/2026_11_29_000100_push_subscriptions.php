<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phone notifications (mobile brief): one row per browser or installed app that allowed
 * notifications, for the workspace it was switched on in. Holds the browser's public keys only;
 * the push service needs nothing else. Per-person push choices live on the user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('membership_id')->nullable();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('p256dh', 120);
            $table->string('auth', 60);
            $table->string('device_id', 64)->nullable();     // the installed app's device key, when sent from it
            $table->string('label', 120)->nullable();        // "iPhone · Safari", shown in the devices list
            $table->timestampTz('test_sent_at')->nullable();
            $table->timestampTz('test_tapped_at')->nullable();
            $table->timestampTz('last_sent_at')->nullable();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestampsTz();
            $table->index(['membership_id']);
            $table->index(['user_id', 'tenant_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('push_preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('push_preferences');
        });
    }
};
