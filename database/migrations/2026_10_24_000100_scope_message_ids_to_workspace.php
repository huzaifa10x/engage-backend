<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * WhatsApp message ids are unique per workspace, not across the whole platform.
 *
 * The old index made a message id unique platform-wide. That breaks the moment the same phone
 * number is connected to a second workspace (after being disconnected in the first): the chat
 * history WhatsApp sends carries the same message ids the first workspace already holds, the
 * database refused every one of them as a duplicate, and the import silently dropped them. The
 * new workspace then showed only messages newer than anything the old workspace had seen.
 *
 * Within one workspace the id is still unique, which is what makes webhook retries, replays and
 * repeated imports safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS messages_wamid_unique');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS messages_tenant_wamid_unique ON messages (tenant_id, wamid) WHERE wamid IS NOT NULL');
    }

    public function down(): void
    {
        // Not restoring the platform-wide rule: once two workspaces hold the same id it cannot be rebuilt.
        DB::statement('DROP INDEX IF EXISTS messages_tenant_wamid_unique');
        DB::statement('CREATE INDEX IF NOT EXISTS messages_wamid_index ON messages (wamid) WHERE wamid IS NOT NULL');
    }
};
