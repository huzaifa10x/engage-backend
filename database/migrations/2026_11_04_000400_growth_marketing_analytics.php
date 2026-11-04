<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Analytics → Marketing (Marketing Analytics Specification §3, §6): the workspace's stage mapping
 * (which CRM stages mean Contacted and Qualified) and the daily cohort table the reports read.
 * Rows are keyed by the lead's first-enquiry date in the workspace time zone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('growth_stage_mapping', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('reporting_step', 20);              // contacted | qualified
            $table->uuid('crm_stage_id');
            $table->uuid('updated_by')->nullable();             // membership
            $table->timestampTz('updated_at')->useCurrent();
            $table->primary(['tenant_id', 'reporting_step', 'crm_stage_id']);
        });
        TenantSchema::check('growth_stage_mapping', 'growth_stage_mapping_step_check', "reporting_step IN ('contacted','qualified')");
        TenantSchema::enableRls('growth_stage_mapping');

        Schema::create('growth_marketing_daily', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->date('local_date');
            $table->string('source_bucket', 40);
            $table->string('campaign_key', 120);
            $table->string('number_id', 40);                   // phone_numbers.id, '' when unknown
            $table->integer('new_leads')->default(0);
            $table->integer('contacted_30d')->default(0);
            $table->integer('qualified_30d')->default(0);
            $table->timestampTz('computed_at')->useCurrent();
            $table->primary(['tenant_id', 'local_date', 'source_bucket', 'campaign_key', 'number_id']);
        });
        TenantSchema::enableRls('growth_marketing_daily');

        Schema::create('growth_marketing_state', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->primary()->constrained('tenants')->cascadeOnDelete();
            $table->timestampTz('computed_at');
        });
        TenantSchema::enableRls('growth_marketing_state');
    }

    public function down(): void
    {
        Schema::dropIfExists('growth_marketing_state');
        Schema::dropIfExists('growth_marketing_daily');
        Schema::dropIfExists('growth_stage_mapping');
    }
};
