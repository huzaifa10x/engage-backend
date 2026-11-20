<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbox & CRM 3.5.
 *  - conversation_resolutions: one row per closure (who, when, why), how it was reopened, and a manager's
 *    flag for review. The Resolved tab lists these rows; each has its own ID.
 *  - contacts: the profile fields of the contact record, its owner, the lead status set by hand, and the
 *    permission to contact (how the person agreed to be contacted, who recorded it, when).
 *  - contact_field_changes: every change to a contact field, with who made it (the panel's change history).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_resolutions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('conversation_id');
            $table->uuid('contact_id')->nullable();
            $table->string('reason', 12);                       // resolved | not_lead | no_action
            $table->uuid('resolved_by_membership_id')->nullable(); // null: a chatbot end step
            $table->timestampTz('resolved_at');
            $table->timestampTz('reopened_at')->nullable();
            $table->string('reopen_how', 10)->nullable();        // customer | agent | flag | message
            $table->uuid('reopened_by_membership_id')->nullable();
            $table->uuid('flagged_by_membership_id')->nullable();
            $table->string('flag_note', 300)->nullable();
            $table->timestampTz('flagged_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'resolved_at']);
            $table->index(['tenant_id', 'conversation_id', 'resolved_at']);
        });
        TenantSchema::check('conversation_resolutions', 'conversation_resolutions_reason_check', "reason IN ('resolved','not_lead','no_action')");
        TenantSchema::enableRls('conversation_resolutions');

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('company', 190)->nullable();
            $table->string('job_title', 120)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('country', 80)->nullable();
            $table->string('language', 40)->nullable();
            $table->string('lead_status', 20)->nullable();
            $table->uuid('owner_membership_id')->nullable();
            $table->jsonb('contact_permission')->nullable();     // {via, note, by, by_name, at}
        });

        Schema::create('contact_field_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('contact_id');
            $table->string('field', 60);
            $table->string('label', 120);
            $table->text('from_value')->nullable();
            $table->text('to_value')->nullable();
            $table->uuid('changed_by_membership_id')->nullable();
            $table->string('changed_by_name', 120)->nullable();   // as it was when it happened
            $table->string('source', 12)->default('user');       // user | api | import | system
            $table->timestampTz('changed_at');

            $table->index(['tenant_id', 'contact_id', 'changed_at']);
        });
        TenantSchema::enableRls('contact_field_changes');
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_field_changes');
        Schema::table('contacts', fn (Blueprint $table) => $table->dropColumn(['company', 'job_title', 'city', 'country', 'language', 'lead_status', 'owner_membership_id', 'contact_permission']));
        Schema::dropIfExists('conversation_resolutions');
    }
};
