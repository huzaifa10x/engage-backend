<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The message visitors send from a tracked website: an editable greeting (and an optional Arabic one,
 * chosen by the page's lang). The reference "(Ref: XG-…)" is not part of it: the tracker always
 * appends it, and it cannot be removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('growth_sites', function (Blueprint $table) {
            $table->string('greeting', 200)->nullable();
            $table->string('greeting_ar', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('growth_sites', fn (Blueprint $t) => $t->dropColumn(['greeting', 'greeting_ar']));
    }
};
