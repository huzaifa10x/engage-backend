<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM stages and stage history (Change Notice CN-01 §II.1).
 *
 * Each workspace defines its own pipeline stages. A contact has at most one current stage.
 * Every change is appended to contact_stage_events (old stage, new stage, who, when) and never
 * edited, so Analytics → Marketing can count "qualified within 30 days" from launch.
 * A stage that was ever used is archived, never deleted, so history keeps pointing at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 40);
            $table->string('color', 12)->default('grey');   // grey | info | good | warn | bad | brand
            $table->integer('position')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->index(['tenant_id', 'position']);
        });
        TenantSchema::tenantKey('crm_stages');
        DB::statement('CREATE UNIQUE INDEX crm_stages_tenant_name_unique ON crm_stages (tenant_id, lower(name)) WHERE archived_at IS NULL');
        TenantSchema::check('crm_stages', 'crm_stages_color_check', "color IN ('grey','info','good','warn','bad','brand')");
        TenantSchema::enableRls('crm_stages');

        Schema::table('contacts', function (Blueprint $table) {
            $table->uuid('stage_id')->nullable();
            $table->timestampTz('stage_changed_at')->nullable();
            $table->index(['tenant_id', 'stage_id']);
        });
        TenantSchema::tenantForeign('contacts', 'stage_id', 'crm_stages');

        Schema::create('contact_stage_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('contact_id');
            $table->uuid('from_stage_id')->nullable();
            $table->uuid('to_stage_id')->nullable();
            $table->uuid('changed_by_membership_id')->nullable();
            $table->string('source', 20)->default('user');  // user | api | import | automation
            $table->timestampTz('changed_at');
            $table->index(['tenant_id', 'contact_id', 'changed_at']);
            $table->index(['tenant_id', 'to_stage_id', 'changed_at']);
        });
        TenantSchema::tenantForeign('contact_stage_events', 'contact_id', 'contacts', 'CASCADE');
        TenantSchema::check('contact_stage_events', 'contact_stage_events_source_check', "source IN ('user','api','import','automation')");
        TenantSchema::enableRls('contact_stage_events');
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_stage_events');
        DB::unprepared('ALTER TABLE contacts DROP CONSTRAINT IF EXISTS contacts_stage_id_tenant_fk');
        Schema::table('contacts', fn (Blueprint $table) => $table->dropColumn(['stage_id', 'stage_changed_at']));
        Schema::dropIfExists('crm_stages');
    }
};
