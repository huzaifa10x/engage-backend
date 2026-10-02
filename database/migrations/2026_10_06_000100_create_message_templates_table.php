<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp message templates, mirrored from Meta per WhatsApp Business Account. Meta is the
 * source of truth: rows are created on submit and kept in step by webhooks + periodic sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('waba_account_id');
            $table->string('meta_template_id', 64)->nullable();
            $table->string('name', 512);
            $table->string('language', 16);
            $table->string('category', 32)->nullable();              // MARKETING | UTILITY | AUTHENTICATION
            $table->string('status', 32)->default('PENDING');        // exactly as Meta reports it
            $table->string('quality_score', 16)->nullable();         // GREEN | YELLOW | RED | UNKNOWN
            $table->string('rejected_reason', 64)->nullable();
            $table->string('parameter_format', 16)->default('POSITIONAL');
            $table->jsonb('components')->nullable();
            $table->uuid('created_by_membership_id')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampTz('deleted_at')->nullable();           // deleted on Meta
            $table->timestampsTz();

            $table->unique(['waba_account_id', 'name', 'language']);
            $table->index(['tenant_id', 'status']);
            $table->index(['waba_account_id', 'meta_template_id']);
        });
        TenantSchema::tenantKey('message_templates');
        TenantSchema::tenantForeign('message_templates', 'waba_account_id', 'waba_accounts', 'CASCADE');
        TenantSchema::enableRls('message_templates');
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
