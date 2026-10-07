<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Demo / contact requests from the public website. Platform data: not tied to a workspace. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('email', 190)->index();
            $table->string('company', 160)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('team_size', 40)->nullable();      // "Just one (SMB)", "6–20 (agency)" …
            $table->string('topic', 40)->default('demo');     // demo | contact | enterprise
            $table->text('message')->nullable();
            $table->string('source', 190)->nullable();        // page the form was sent from
            $table->string('ip_address', 45)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_leads');
    }
};
