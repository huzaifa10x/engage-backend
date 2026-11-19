<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbox & CRM 3.3, B1: the enquiry card (what it is about, its value, expected close date, owner),
 * edited without overwriting a teammate (details_version), and next steps on an enquiry or a contact.
 * Enquiries are closed through their stage, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enquiries', function (Blueprint $table) {
            $table->string('title', 120)->nullable();
            $table->decimal('value', 14, 2)->nullable();
            $table->string('value_period', 5)->nullable();          // once | month | year
            $table->date('expected_close_on')->nullable();
            $table->integer('details_version')->default(0);
            $table->uuid('details_updated_by')->nullable();
            $table->timestampTz('details_updated_at')->nullable();
        });
        TenantSchema::check('enquiries', 'enquiries_value_period_check', "value_period IS NULL OR value_period IN ('once','month','year')");

        Schema::create('next_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('contact_id');
            $table->uuid('enquiry_id')->nullable();                 // service chats can have contact-level steps
            $table->string('type', 10);                             // call | message | meeting | quote | other
            $table->string('title', 160);
            $table->timestampTz('due_at');
            $table->uuid('assigned_membership_id')->nullable();
            $table->uuid('created_by_membership_id')->nullable();
            $table->timestampTz('done_at')->nullable();
            $table->uuid('done_by_membership_id')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 120)->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'contact_id', 'due_at']);
            $table->index(['tenant_id', 'enquiry_id']);
            $table->index(['tenant_id', 'assigned_membership_id', 'due_at']);
        });
        TenantSchema::check('next_steps', 'next_steps_type_check', "type IN ('call','message','meeting','quote','other')");
        TenantSchema::enableRls('next_steps');
    }

    public function down(): void
    {
        Schema::dropIfExists('next_steps');
        Schema::table('enquiries', function (Blueprint $table) {
            $table->dropColumn(['title', 'value', 'value_period', 'expected_close_on', 'details_version', 'details_updated_by', 'details_updated_at']);
        });
    }
};
