<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Global identities. Tenant access is ONLY via tenant_memberships (no users.tenant_id).
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('email', 190);
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('locale', 10)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->uuid('last_active_tenant_id')->nullable(); // FK added with tenants
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('disabled_at')->nullable();
            $table->timestampsTz();
        });

        DB::unprepared('CREATE UNIQUE INDEX users_email_unique ON users (lower(email))');

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 190)->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        // Used only if SESSION_DRIVER=database (default is Valkey).
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
