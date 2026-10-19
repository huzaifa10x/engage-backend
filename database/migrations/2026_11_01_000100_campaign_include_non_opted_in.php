<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing campaigns go to opted-in contacts by default. The sender can tick "Include contacts who
 * have not opted in" to also reach contacts whose consent is unknown (never the ones who opted out).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->boolean('include_non_opted_in')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn('include_non_opted_in'));
    }
};
