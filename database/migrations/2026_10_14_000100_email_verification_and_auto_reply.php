<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email verification becomes mandatory for new sign-ups. Everyone who already has an account is
 * treated as verified, so nobody is locked out by this release. Plus per-conversation auto-reply.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);

        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('auto_reply_enabled')->default(true);   // false = "no automatic replies in this conversation"
            $table->timestampTz('last_auto_reply_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['auto_reply_enabled', 'last_auto_reply_at']);
        });
    }
};
