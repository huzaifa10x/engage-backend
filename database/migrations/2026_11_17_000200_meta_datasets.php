<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversions to Meta (batch 3a): the Meta dataset of each WhatsApp Business Account that reports
 * conversions, and what the account's token was last seen to allow (whatsapp_business_manage_events).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_datasets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();   // the workspace
            $table->uuid('waba_account_id');
            $table->string('waba_id', 32);
            $table->string('dataset_id', 32);
            $table->boolean('enabled')->default(true);                                      // conversion reporting on for this account
            $table->timestampsTz();

            $table->unique(['tenant_id', 'waba_id']);
        });
        TenantSchema::enableRls('meta_datasets');
        TenantSchema::tenantForeign('meta_datasets', 'waba_account_id', 'waba_accounts', 'CASCADE');

        Schema::table('waba_accounts', function (Blueprint $table) {
            // From debug_token: whether the account's token carries whatsapp_business_manage_events for it.
            $table->boolean('events_permission')->nullable();
            $table->timestampTz('events_permission_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('waba_accounts', fn (Blueprint $table) => $table->dropColumn(['events_permission', 'events_permission_checked_at']));
        Schema::dropIfExists('meta_datasets');
    }
};
