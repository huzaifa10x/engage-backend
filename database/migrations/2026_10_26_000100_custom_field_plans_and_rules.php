<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Custom contact fields per plan: Free 5, Starter 25, Growth 100, Pro unlimited (fair use),
 * Enterprise unlimited with extra field types and validation rules.
 *
 * The new limits apply to every existing version of the five standard plans (workspaces keep
 * their plan; only this one limit changes). Fields beyond a lower limit are never deleted.
 */
return new class extends Migration
{
    private const LIMITS = [
        'free' => [5, null],
        'starter' => [25, null],
        'growth' => [100, null],
        'pro' => [null, ['fair_use' => 500]],
        'enterprise' => [null, ['advanced' => true]],
    ];

    public function up(): void
    {
        Schema::table('contact_fields', function (Blueprint $table) {
            $table->jsonb('options')->nullable();   // choices of a select / multi-select field
            $table->jsonb('rules')->nullable();     // required, min, max, min_length, max_length, pattern
        });
        DB::statement('ALTER TABLE contact_fields DROP CONSTRAINT IF EXISTS contact_fields_type_check');
        TenantSchema::check('contact_fields', 'contact_fields_type_check', "type IN ('text','number','date','email','url','phone','boolean','select','multi_select')");

        app(TenantContext::class)->bypass(function (): void {
            $feature = DB::table('features')->where('key', 'custom_fields')->value('id');
            if ($feature === null) {
                return; // a fresh database: the plan catalog seeder creates everything with these limits
            }
            foreach (self::LIMITS as $plan => [$limit, $config]) {
                $versions = DB::table('plan_versions')->join('plans', 'plans.id', '=', 'plan_versions.plan_id')->where('plans.key', $plan)->pluck('plan_versions.id');
                DB::table('plan_version_features')->where('feature_id', $feature)->whereIn('plan_version_id', $versions)
                    ->update(['enabled' => true, 'limit_value' => $limit, 'config' => $config === null ? null : json_encode($config)]);
            }
        });
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE contact_fields DROP CONSTRAINT IF EXISTS contact_fields_type_check');
        TenantSchema::check('contact_fields', 'contact_fields_type_check', "type IN ('text','number','date')");
        Schema::table('contact_fields', function (Blueprint $table) {
            $table->dropColumn(['options', 'rules']);
        });
    }
};
