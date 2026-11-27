<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pilot decision: SSO / SAML, white-label and reseller sub-accounts are not offered. Switched off on
 * every plan version (the features stay in the catalogue), and the Enterprise description no longer
 * promises SSO/SAML.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('features')->whereIn('key', ['sso', 'white_label', 'sub_accounts'])->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('plan_version_features')->whereIn('feature_id', $ids)->update(['enabled' => false]);
        }
        DB::table('plans')->where('key', 'enterprise')->where('description', 'ilike', '%SSO%')
            ->update(['description' => 'Large orgs and agencies needing custom retention, an SLA and a dedicated account manager.']);
    }

    public function down(): void
    {
        // Not reversible: the features were never delivered.
    }
};
