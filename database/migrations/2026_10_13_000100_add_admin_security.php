<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Super Admin security: a selectable 2FA method (authenticator app or email code), the list of
 * active sign-ins per admin (so one can be ended remotely), and an append-only login history.
 * Platform tables — no tenant, no row-level security.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->string('two_factor_method', 8)->nullable(); // app | email | null (off)
        });
        DB::table('platform_admins')->whereNotNull('two_factor_confirmed_at')->update(['two_factor_method' => 'app']);

        Schema::create('admin_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('platform_admin_id');
            $table->char('session_hash', 64)->unique();          // sha256 of the session id — never the id itself
            $table->string('ip', 45)->nullable();
            $table->string('country', 2)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('browser', 40)->nullable();
            $table->string('os', 40)->nullable();
            $table->string('device', 16)->nullable();            // Desktop | Mobile | Tablet
            $table->timestampTz('last_active_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->foreign('platform_admin_id')->references('id')->on('platform_admins')->cascadeOnDelete();
            $table->index(['platform_admin_id', 'last_active_at']);
        });

        Schema::create('admin_login_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('platform_admin_id')->nullable();       // null: the email matched no admin
            $table->string('email', 190)->nullable();            // what was typed at the login form
            $table->string('event', 24);                         // login | failed_password | failed_two_factor | logout | session_revoked
            $table->string('method', 16)->nullable();            // app | email | recovery | none
            $table->string('ip', 45)->nullable();
            $table->string('country', 2)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('browser', 40)->nullable();
            $table->string('os', 40)->nullable();
            $table->string('device', 16)->nullable();
            $table->timestampTz('created_at');

            $table->foreign('platform_admin_id')->references('id')->on('platform_admins')->nullOnDelete();
            $table->index(['platform_admin_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_login_events');
        Schema::dropIfExists('admin_sessions');
        Schema::table('platform_admins', function (Blueprint $table) {
            $table->dropColumn('two_factor_method');
        });
    }
};
