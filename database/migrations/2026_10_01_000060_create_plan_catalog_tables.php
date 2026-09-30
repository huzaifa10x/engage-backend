<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Global catalog (no tenant data, no RLS). Plans → Plan Versions → Features.
     * Published versions are immutable; changes ship as a new version.
     */
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 64)->unique();
            $table->string('name', 120);
            $table->string('type', 16);
            $table->string('unit', 32)->nullable();
            $table->text('description')->nullable();
            $table->timestampsTz();
        });
        TenantSchema::check('features', 'features_type_check', "type IN ('boolean','limit')");

        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 32)->unique();
            $table->string('name', 64);
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(true);
            $table->smallInteger('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('plan_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('plans')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('draft');
            $table->bigInteger('price_monthly_minor')->nullable(); // NULL = custom pricing
            $table->bigInteger('price_yearly_minor')->nullable();
            $table->char('currency', 3)->default('USD');
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();

            $table->unique(['plan_id', 'version']);
        });
        TenantSchema::check('plan_versions', 'plan_versions_status_check', "status IN ('draft','active','retired')");
        DB::unprepared("CREATE UNIQUE INDEX plan_versions_one_active ON plan_versions (plan_id) WHERE status = 'active'");

        Schema::create('plan_version_features', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_version_id')->constrained('plan_versions')->cascadeOnDelete();
            $table->foreignUuid('feature_id')->constrained('features')->restrictOnDelete();
            $table->boolean('enabled')->default(false);
            $table->bigInteger('limit_value')->nullable(); // NULL = unlimited (limit features)
            $table->jsonb('config')->nullable();
            $table->timestampsTz();

            $table->unique(['plan_version_id', 'feature_id']);
        });
        TenantSchema::check('plan_version_features', 'plan_version_features_limit_check', 'limit_value IS NULL OR limit_value >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_version_features');
        Schema::dropIfExists('plan_versions');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('features');
    }
};
