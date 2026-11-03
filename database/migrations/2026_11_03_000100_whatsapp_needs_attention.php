<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Needs attention": what the periodic Meta check sets instead of disconnecting. A warning for the
 * 10X Engage team; the number or account keeps working. attention_detail holds Meta's response.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['phone_numbers', 'waba_accounts'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('attention_reason', 32)->nullable();
                $table->timestampTz('attention_since')->nullable();
                $table->jsonb('attention_detail')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['phone_numbers', 'waba_accounts'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['attention_reason', 'attention_since', 'attention_detail']));
        }
    }
};
