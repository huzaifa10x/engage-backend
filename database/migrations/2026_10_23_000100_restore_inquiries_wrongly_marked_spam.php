<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The hidden-field bot trap marked real website inquiries as spam (browsers filled the field in).
 * Everything it caught so far goes back to "new", so it shows in Super Admin → Inquiries.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sales_leads')->where('status', 'spam')->update(['status' => 'new']);
    }

    public function down(): void
    {
        // Nothing to undo.
    }
};
