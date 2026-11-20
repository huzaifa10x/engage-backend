<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Batch 7: nothing is deleted except through the legal deletion request in Settings › Data, so the
 * "Delete contacts" permission is gone from every role.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Roles are tenant rows under row-level security: the migration updates every workspace's.
        DB::statement("SELECT set_config('app.rls_bypass', 'on', true)");
        DB::statement("UPDATE roles SET permissions = permissions - 'contacts.delete' WHERE jsonb_exists(permissions, 'contacts.delete')");
    }

    public function down(): void
    {
        // Not restored: contacts are erased only through the legal deletion request.
    }
};
