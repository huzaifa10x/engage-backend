<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the opt-in of contacts with no recorded consent was collected ("Checkout checkbox on our
 * website"). Required when a campaign includes them: Meta needs an opt-in before any business-initiated
 * message, and an Unknown consent state is no proof of one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('consent_basis', 190)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn('consent_basis'));
    }
};
