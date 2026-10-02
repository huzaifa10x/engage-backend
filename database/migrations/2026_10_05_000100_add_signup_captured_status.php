<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Embedded Signup attempts gain the temporary `signup_captured` status: the popup returned its
 * IDs, but the number is not onboarded until the subscription checks and provisioning pass.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('ALTER TABLE embedded_signup_attempts DROP CONSTRAINT IF EXISTS embedded_signup_attempts_status_check');
        TenantSchema::check('embedded_signup_attempts', 'embedded_signup_attempts_status_check',
            "status IN ('started','signup_captured','exchanging','provisioning','completed','failed','cancelled')");
    }

    public function down(): void
    {
        DB::unprepared("UPDATE embedded_signup_attempts SET status = 'failed' WHERE status = 'signup_captured'");
        DB::unprepared('ALTER TABLE embedded_signup_attempts DROP CONSTRAINT IF EXISTS embedded_signup_attempts_status_check');
        TenantSchema::check('embedded_signup_attempts', 'embedded_signup_attempts_status_check',
            "status IN ('started','exchanging','provisioning','completed','failed','cancelled')");
    }
};
