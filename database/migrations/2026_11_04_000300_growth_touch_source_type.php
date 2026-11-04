<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Paid ads and boosted posts are told apart (referral source_type). Existing contact touches get
 * the source_type of their evidence, and touches from a boosted post are labelled "Meta boosted post".
 */
return new class extends Migration
{
    public function up(): void
    {
        app(TenantContext::class)->bypass(function (): void {
            foreach (['acquisition_original', 'acquisition_latest'] as $column) {
                DB::statement(<<<SQL
                    UPDATE contacts c
                    SET {$column} = c.{$column}
                        || jsonb_build_object('source_type', e.provider_fields->'referral'->>'source_type')
                        || CASE WHEN e.provider_fields->'referral'->>'source_type' = 'post'
                                THEN jsonb_build_object('label', replace(c.{$column}->>'label', 'Meta ad', 'Meta boosted post'))
                                ELSE '{}'::jsonb END
                    FROM growth_evidence e
                    WHERE e.id::text = c.{$column}->>'evidence_id'
                      AND e.evidence_type = 'meta_ctwa'
                      AND e.provider_fields->'referral'->>'source_type' IS NOT NULL
                      AND c.{$column}->>'source_type' IS NULL
                SQL);
            }
        });
    }

    public function down(): void
    {
        // Labels stay readable either way; nothing to undo.
    }
};
