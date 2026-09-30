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
        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('email', 190);
            $table->foreignUuid('role_id')->constrained('roles')->restrictOnDelete();
            $table->char('token_hash', 64)->unique(); // sha256(token); plaintext only in the email
            $table->foreignUuid('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'created_at']);
        });

        DB::unprepared('CREATE UNIQUE INDEX invitations_open_email_unique ON invitations (tenant_id, lower(email)) WHERE accepted_at IS NULL AND revoked_at IS NULL');

        TenantSchema::enableRls('invitations');
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
