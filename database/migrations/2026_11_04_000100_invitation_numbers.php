<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The WhatsApp numbers an invited Agent (or any role without all numbers) gets when they accept.
 * Without this, a new agent joined with no numbers: an empty inbox and "no access" on assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', fn (Blueprint $table) => $table->jsonb('phone_number_ids')->nullable());
    }

    public function down(): void
    {
        Schema::table('invitations', fn (Blueprint $table) => $table->dropColumn('phone_number_ids'));
    }
};
