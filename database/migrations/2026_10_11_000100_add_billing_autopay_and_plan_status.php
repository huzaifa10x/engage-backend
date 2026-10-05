<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Auto-pay per subscription (Stripe collection method) and an explicit on/off switch per plan. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('auto_pay')->default(true); // true = card charged automatically; false = invoice sent, paid manually
        });
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('is_active')->default(true); // inactive plans cannot be bought; existing subscribers keep them
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('auto_pay');
        });
    }
};
