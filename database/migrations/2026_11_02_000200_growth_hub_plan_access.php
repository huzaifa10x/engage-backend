<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Change Notice CN-01 §III: Starter shows the Meta ad source chip only; Growth, Pro and Enterprise
 * get Growth Hub. Growth Hub reads the existing lead_source_tracking feature, which no code path
 * gated before this release — so aligning the Starter versions to level "basic" changes nothing a
 * subscriber had. Growth, Pro and Enterprise already include it. Custom plans keep their value.
 */
return new class extends Migration
{
    public function up(): void
    {
        $feature = DB::table('features')->where('key', 'lead_source_tracking')->value('id');
        if ($feature === null) {
            return; // fresh database: PlanCatalogSeeder seeds the right values
        }

        $starter = DB::table('plan_versions')->join('plans', 'plans.id', '=', 'plan_versions.plan_id')
            ->where('plans.key', 'starter')->pluck('plan_versions.id');

        DB::table('plan_version_features')->where('feature_id', $feature)->whereIn('plan_version_id', $starter)
            ->update(['enabled' => true, 'config' => json_encode(['level' => 'basic']), 'updated_at' => now()]);

        // Entitlements are cached for a few minutes per workspace: refresh them now.
        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            Cache::forget("entitlements:v2:{$tenantId}");
        }
    }

    public function down(): void
    {
        $feature = DB::table('features')->where('key', 'lead_source_tracking')->value('id');
        $starter = DB::table('plan_versions')->join('plans', 'plans.id', '=', 'plan_versions.plan_id')
            ->where('plans.key', 'starter')->pluck('plan_versions.id');
        DB::table('plan_version_features')->where('feature_id', $feature)->whereIn('plan_version_id', $starter)
            ->update(['enabled' => true, 'config' => null, 'updated_at' => now()]);
    }
};
