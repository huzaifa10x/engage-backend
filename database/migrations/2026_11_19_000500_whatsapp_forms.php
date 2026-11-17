<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp Forms (batch 5, item 5b): simple forms whose questions and options are fixed in the form
 * (no live data endpoint), made here and created, updated and published on Meta through the Flows API.
 *
 *  - forms: one per WhatsApp Business Account, with its current version.
 *  - form_versions: the form as it was at each version, and its Meta flow. A published version is never
 *    changed: editing it makes a new version, and conversations that got the old one keep it.
 *  - form_submissions: the answers, as received and as saved on the contact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('waba_account_id');
            $table->string('name', 80);
            $table->string('kind', 12)->default('custom');                 // booking | lead | custom
            $table->uuid('current_version_id')->nullable();
            $table->uuid('created_by_membership_id')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'waba_account_id']);
        });
        TenantSchema::enableRls('forms');
        TenantSchema::tenantKey('forms');
        TenantSchema::tenantForeign('forms', 'waba_account_id', 'waba_accounts', 'CASCADE');

        Schema::create('form_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('form_id');
            $table->unsignedInteger('version');
            $table->jsonb('definition');                                   // title, intro, submit label, fields
            $table->string('meta_flow_id', 32)->nullable();
            $table->string('meta_status', 16)->default('NOT_CREATED');     // NOT_CREATED | DRAFT | PUBLISHED | DEPRECATED | BLOCKED | THROTTLED
            $table->jsonb('meta_errors')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('checked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['form_id', 'version']);
        });
        TenantSchema::enableRls('form_versions');
        TenantSchema::tenantKey('form_versions');
        TenantSchema::tenantForeign('form_versions', 'form_id', 'forms', 'CASCADE');

        Schema::create('form_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('form_id');
            $table->uuid('form_version_id');
            $table->uuid('contact_id');
            $table->uuid('conversation_id')->nullable();
            $table->uuid('message_id');
            $table->uuid('session_id')->nullable();
            $table->jsonb('answers');                                      // as received
            $table->jsonb('saved');                                        // label → value as saved (dates day-first)
            $table->boolean('consent')->nullable();                        // the form's consent question, ticked or not
            $table->timestampTz('created_at');
            $table->unique(['tenant_id', 'message_id']);
            $table->index(['tenant_id', 'form_id', 'created_at']);
        });
        TenantSchema::enableRls('form_submissions');
        TenantSchema::tenantForeign('form_submissions', 'form_version_id', 'form_versions', 'CASCADE');
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('form_versions');
        Schema::dropIfExists('forms');
    }
};
