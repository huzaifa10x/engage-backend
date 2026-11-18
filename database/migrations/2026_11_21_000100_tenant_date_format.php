<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 6, item 1: the workspace's date format, a setting of its own (never worked out from the time
 * zone). Existing workspaces keep reading dates day-first, as they do today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('date_format', 3)->default('dmy');
        });
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_date_format_check CHECK (date_format IN ('dmy','mdy','ymd'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_date_format_check');
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('date_format'));
    }
};
