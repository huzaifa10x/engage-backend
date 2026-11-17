<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estimated Meta cost before sending (batch 4, item 2).
 *
 *  - meta_rates: Meta's per-message rate card, one row per market and date it applies from (USD).
 *    Platform-wide, not per workspace. Updated with `engage:meta-rates:import` from Meta's CSV.
 *  - campaigns.cost_estimate: the estimate shown at launch, with the date of the rates it used.
 *  - message_templates.previous_category / category_changed_at: Meta re-categorised the template.
 *
 * Seeded with the rate card that applies from 1 October 2026.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_rates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->date('effective_from');
            $table->string('market', 64);
            $table->char('currency', 3)->default('USD');
            $table->decimal('marketing', 10, 6);
            $table->decimal('utility', 10, 6);
            $table->decimal('authentication', 10, 6);
            $table->decimal('authentication_international', 10, 6)->nullable();
            $table->string('source', 190)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['effective_from', 'market']);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->jsonb('cost_estimate')->nullable();
        });

        Schema::table('message_templates', function (Blueprint $table) {
            $table->string('previous_category', 32)->nullable();
            $table->timestampTz('category_changed_at')->nullable();
        });

        $rows = [
            // market, marketing, utility, authentication, authentication-international
            ['Argentina', 0.0618, 0.0260, 0.0260, null],
            ['Brazil', 0.0625, 0.0068, 0.0068, null],
            ['Chile', 0.0889, 0.0200, 0.0200, null],
            ['Colombia', 0.0125, 0.0008, 0.0008, null],
            ['Egypt', 0.0644, 0.0036, 0.0036, 0.0650],
            ['France', 0.0859, 0.0300, 0.0300, null],
            ['Germany', 0.1365, 0.0550, 0.0550, null],
            ['Hong Kong', 0.0732, 0.0260, 0.0260, null],
            ['India', 0.0118, 0.0014, 0.0014, 0.0304],
            ['Indonesia', 0.0411, 0.0250, 0.0250, 0.1360],
            ['Israel', 0.0353, 0.0053, 0.0053, null],
            ['Italy', 0.0795, 0.0300, 0.0300, null],
            ['Kuwait', 0.0792, 0.0440, 0.0440, 0.1200],
            ['Malaysia', 0.0860, 0.0140, 0.0140, 0.0418],
            ['Mexico', 0.0397, 0.0085, 0.0085, null],
            ['Netherlands', 0.1597, 0.0500, 0.0500, null],
            ['Nigeria', 0.0516, 0.0067, 0.0067, 0.0750],
            ['Oman', 0.0341, 0.0247, 0.0247, 0.0600],
            ['Pakistan', 0.0473, 0.0150, 0.0150, 0.0750],
            ['Peru', 0.0703, 0.0300, 0.0300, null],
            ['Poland', 0.0366, 0.0122, 0.0122, null],
            ['Qatar', 0.0341, 0.0120, 0.0120, null],
            ['Russia', 0.0802, 0.0400, 0.0400, null],
            ['Saudi Arabia', 0.0576, 0.0107, 0.0107, 0.0598],
            ['Singapore', 0.0732, 0.0160, 0.0160, null],
            ['South Africa', 0.0379, 0.0095, 0.0095, 0.0200],
            ['Spain', 0.0707, 0.0200, 0.0200, null],
            ['Turkey', 0.0109, 0.0009, 0.0009, null],
            ['United Arab Emirates', 0.0576, 0.0157, 0.0157, 0.0510],
            ['United Kingdom', 0.0635, 0.0220, 0.0220, null],
            ['North America', 0.0250, 0.0034, 0.0034, null],
            ['Rest of Africa', 0.0225, 0.0040, 0.0040, null],
            ['Rest of Asia Pacific', 0.0842, 0.0113, 0.0113, null],
            ['Rest of Central & Eastern Europe', 0.0860, 0.0212, 0.0212, null],
            ['Rest of Latin America', 0.0740, 0.0113, 0.0113, null],
            ['Rest of Middle East', 0.0392, 0.0091, 0.0091, null],
            ['Rest of Western Europe', 0.0592, 0.0171, 0.0171, null],
            ['Other', 0.0604, 0.0077, 0.0077, null],
        ];
        DB::table('meta_rates')->insert(array_map(fn (array $r) => [
            'effective_from' => '2026-10-01', 'market' => $r[0], 'currency' => 'USD',
            'marketing' => $r[1], 'utility' => $r[2], 'authentication' => $r[3], 'authentication_international' => $r[4],
            'source' => 'Meta rate card, USD, from 1 Oct 2026 (seed)',
        ], $rows));
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn(['previous_category', 'category_changed_at']);
        });
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('cost_estimate');
        });
        Schema::dropIfExists('meta_rates');
    }
};
