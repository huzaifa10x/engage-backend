<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A review account (Meta App Review): signs in with email + password only, never asked for an
 * authenticator code or an emailed code. Turned on and off by a Super Admin, with a reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('review_account_at')->nullable();
            $table->string('review_account_note', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['review_account_at', 'review_account_note']));
    }
};
