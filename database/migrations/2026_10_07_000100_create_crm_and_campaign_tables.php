<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contacts & CRM completion (tags, custom field definitions, segments) and Campaigns.
 * A segment is a saved rule, never a stored list: membership is evaluated when it is read.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("ALTER TABLE contacts ADD COLUMN tags text[] NOT NULL DEFAULT '{}'");
        DB::unprepared('CREATE INDEX contacts_tags_gin ON contacts USING GIN (tags)');

        Schema::create('contact_tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 40);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'name']);
        });
        TenantSchema::enableRls('contact_tags');

        Schema::create('contact_fields', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('key', 40);                               // key inside contacts.custom_fields
            $table->string('label', 60);
            $table->string('type', 12)->default('text');             // text | number | date
            $table->timestampsTz();

            $table->unique(['tenant_id', 'key']);
        });
        TenantSchema::check('contact_fields', 'contact_fields_type_check', "type IN ('text','number','date')");
        TenantSchema::enableRls('contact_fields');

        Schema::create('segments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 80);
            $table->string('match', 3)->default('all');              // all = AND, any = OR
            $table->jsonb('rules');
            $table->uuid('created_by_membership_id')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'name']);
        });
        TenantSchema::tenantKey('segments');
        TenantSchema::check('segments', 'segments_match_check', "match IN ('all','any')");
        TenantSchema::enableRls('segments');

        Schema::create('campaigns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 120);
            $table->uuid('phone_number_id');
            $table->uuid('message_template_id')->nullable();
            $table->string('template_name', 512);
            $table->string('template_language', 16);
            $table->string('template_category', 32)->nullable();
            $table->jsonb('variables')->nullable();                  // {header:[], body:[], buttons:{}} — may contain {{contact.*}} tokens
            $table->uuid('media_id')->nullable();                    // media header
            $table->uuid('segment_id')->nullable();                  // null = all contacts
            $table->jsonb('audience')->nullable();                   // rule snapshot taken at launch
            $table->string('status', 16)->default('draft');
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->unsignedInteger('matched_count')->default(0);    // contacts that fit the segment
            $table->unsignedInteger('eligible_count')->default(0);   // … and may be messaged (consent)
            $table->string('failure_reason', 300)->nullable();
            $table->uuid('created_by_membership_id')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['status', 'scheduled_at']);
        });
        TenantSchema::tenantKey('campaigns');
        TenantSchema::tenantForeign('campaigns', 'phone_number_id', 'phone_numbers', 'CASCADE');
        TenantSchema::check('campaigns', 'campaigns_status_check', "status IN ('draft','scheduled','sending','completed','cancelled','failed')");
        TenantSchema::enableRls('campaigns');

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('campaign_id');
            $table->uuid('contact_id');
            $table->uuid('message_id')->nullable();
            $table->string('status', 12)->default('pending');        // pending | queued | skipped | failed
            $table->string('reason', 190)->nullable();               // why skipped / failed before sending
            $table->timestampsTz();

            $table->unique(['campaign_id', 'contact_id']);
            $table->index(['campaign_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
        TenantSchema::tenantForeign('campaign_recipients', 'campaign_id', 'campaigns', 'CASCADE');
        TenantSchema::tenantForeign('campaign_recipients', 'contact_id', 'contacts', 'CASCADE');
        TenantSchema::check('campaign_recipients', 'campaign_recipients_status_check', "status IN ('pending','queued','skipped','failed')");
        TenantSchema::enableRls('campaign_recipients');

        Schema::table('messages', function (Blueprint $table) {
            $table->uuid('campaign_id')->nullable();
            $table->index(['campaign_id', 'status']);
            $table->index(['tenant_id', 'direction', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['campaign_id', 'status']);
            $table->dropIndex(['tenant_id', 'direction', 'created_at']);
            $table->dropColumn('campaign_id');
        });
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('segments');
        Schema::dropIfExists('contact_fields');
        Schema::dropIfExists('contact_tags');
        DB::unprepared('DROP INDEX IF EXISTS contacts_tags_gin');
        DB::unprepared('ALTER TABLE contacts DROP COLUMN IF EXISTS tags');
    }
};
