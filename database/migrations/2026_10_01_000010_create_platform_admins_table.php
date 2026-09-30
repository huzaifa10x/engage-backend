<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_admins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('password');
            $table->string('role', 32)->default('support');
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();
            $table->rememberToken();
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('disabled_at')->nullable();
            $table->timestampsTz();
        });

        DB::unprepared('CREATE UNIQUE INDEX platform_admins_email_unique ON platform_admins (lower(email))');
        TenantSchema::check('platform_admins', 'platform_admins_role_check', "role IN ('super_admin','operations','support','finance')");
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admins');
    }
};
