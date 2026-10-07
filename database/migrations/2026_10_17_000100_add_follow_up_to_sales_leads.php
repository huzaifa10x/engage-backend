<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Follow-up tracking for website inquiries (Super Admin → Inquiries). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $table) {
            $table->string('status', 16)->default('new')->index();   // new | contacted | closed
            $table->text('admin_note')->nullable();                   // internal, never shown to the visitor
            $table->uuid('handled_by_admin_id')->nullable();
            $table->timestampTz('handled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_leads', function (Blueprint $table) {
            $table->dropColumn(['status', 'admin_note', 'handled_by_admin_id', 'handled_at']);
        });
    }
};
