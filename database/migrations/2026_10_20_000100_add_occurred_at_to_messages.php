<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Messages are shown in the order they HAPPENED, not the order they were saved.
 *
 * Until now a conversation was sorted by the time each row was stored. That is the same thing for
 * live messages, but chat history imported from the WhatsApp Business app is stored today while
 * its messages are weeks or months old, so imported messages landed between today's.
 *
 * occurred_at is the message's real time: WhatsApp's own timestamp when there is one, otherwise
 * the moment we created it. It is set once and is what a conversation is sorted and paged by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestampTz('occurred_at')->nullable();
        });

        // Existing rows: WhatsApp's timestamp where we have it. One statement; fast even for large tables.
        DB::statement('UPDATE messages SET occurred_at = COALESCE(meta_timestamp, created_at)');

        Schema::table('messages', function (Blueprint $table) {
            $table->index(['conversation_id', 'occurred_at', 'id'], 'messages_conversation_occurred_index');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_conversation_occurred_index');
            $table->dropColumn('occurred_at');
        });
    }
};
