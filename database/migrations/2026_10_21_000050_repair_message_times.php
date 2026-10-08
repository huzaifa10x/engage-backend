<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repairs the fill-in of messages.occurred_at.
 *
 * The migration that added the column (2026_10_20_000100) also tried to fill it for the messages
 * that already existed, but tenant tables are protected by row-level security, which applies to
 * migrations too, so that statement updated nothing. Messages stored before that deploy were left
 * without a time and sorted to the wrong end of their conversation. This fills them in properly.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(TenantContext::class)->bypass(function (): void {
            DB::statement('UPDATE messages SET occurred_at = COALESCE(meta_timestamp, created_at) WHERE occurred_at IS NULL');
        });
    }

    public function down(): void
    {
        // Nothing to undo: the values are correct either way.
    }
};
