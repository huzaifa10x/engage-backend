<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Workspace settings: company details and address (also printed on invoices via Stripe). */
return new class extends Migration
{
    private const COLUMNS = ['website' => 190, 'phone' => 32, 'industry' => 60, 'company_size' => 16, 'address_line1' => 190, 'address_line2' => 190, 'city' => 100, 'region' => 100, 'postal_code' => 20];

    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            foreach (self::COLUMNS as $column => $length) {
                $table->string($column, $length)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(array_keys(self::COLUMNS));
        });
    }
};
