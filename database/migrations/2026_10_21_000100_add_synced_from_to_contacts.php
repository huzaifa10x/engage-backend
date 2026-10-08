<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which WhatsApp number a contact was synced from (coexistence: the phone's address book and
 * chat history). Empty for contacts that were added by hand, imported from a file, created
 * through the API, or that simply wrote in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->uuid('synced_from_phone_number_id')->nullable();
            $table->index(['tenant_id', 'synced_from_phone_number_id'], 'contacts_synced_from_index');
        });

        // Tenant tables are protected by row-level security, which applies to migrations as well: without
        // this, the statements below would silently update nothing.
        app(TenantContext::class)->bypass(function (): void {
            // Contacts synced before this column existed. First choice: the number of their earliest
            // conversation (history always arrives through the number it belongs to) …
            DB::statement(<<<'SQL'
                UPDATE contacts c SET synced_from_phone_number_id = first_chat.phone_number_id
                FROM (
                    SELECT DISTINCT ON (contact_id) contact_id, phone_number_id
                    FROM conversations ORDER BY contact_id, created_at, id
                ) first_chat
                WHERE first_chat.contact_id = c.id AND c.source IN ('app_sync', 'history') AND c.synced_from_phone_number_id IS NULL
            SQL);

            // … otherwise (an address-book contact with no chat yet): the workspace's coexistence
            // number, when there is exactly one, so there is no doubt which it was.
            DB::statement(<<<'SQL'
                UPDATE contacts c SET synced_from_phone_number_id = single_number.id
                FROM (
                    SELECT tenant_id, (array_agg(id))[1] AS id FROM phone_numbers
                    WHERE onboarding_type = 'coexistence' GROUP BY tenant_id HAVING count(*) = 1
                ) single_number
                WHERE single_number.tenant_id = c.tenant_id AND c.source IN ('app_sync', 'history') AND c.synced_from_phone_number_id IS NULL
            SQL);
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_synced_from_index');
            $table->dropColumn('synced_from_phone_number_id');
        });
    }
};
