<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remember this device (30 days) and email notification preferences, both per person:
 *  • login_trust_reset_at: devices remembered before this moment must enter a code again
 *    ("Forget remembered devices", or a password reset);
 *  • email_preferences: only the categories the person switched off ({"inbox_mentions": false}).
 *    Empty means every email is on, so nothing changes for existing accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('login_trust_reset_at')->nullable();
            $table->jsonb('email_preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['login_trust_reset_at', 'email_preferences']));
    }
};
