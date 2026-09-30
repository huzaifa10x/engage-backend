<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Encrypted secrets (Meta business tokens, registration PINs). Other tables hold only a
     * secret_id reference — a leaked row dump never contains a usable token. Read exclusively
     * through SecretStore inside a platform bypass; tenants have no direct access.
     */
    public function up(): void
    {
        Schema::create('secrets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->string('purpose', 64);
            $table->string('key_id', 16);
            $table->text('ciphertext');
            $table->timestampTz('rotated_at')->nullable();
            $table->timestampTz('destroyed_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'purpose']);
            $table->index('key_id');
        });

        TenantSchema::enableRls('secrets', using: 'engage_rls_bypass()', check: 'engage_rls_bypass()');
    }

    public function down(): void
    {
        Schema::dropIfExists('secrets');
    }
};
