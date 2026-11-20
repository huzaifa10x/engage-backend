<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contacts & CRM 3.5: the lifecycle stage is kept on the contact, because it only moves forward
 * (a customer asking about something new stays a Customer) and the Contacts list filters and counts by it.
 * Existing contacts are worked out once here; from then on enquiries, closures and consent move it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('lifecycle', 12)->nullable();
            $table->index(['tenant_id', 'lifecycle']);
        });
        // A CRM contact can be added with an email only; it joins the inbox when a WhatsApp chat starts.
        DB::unprepared('ALTER TABLE contacts DROP CONSTRAINT IF EXISTS contacts_identity_check');
        DB::unprepared("ALTER TABLE contacts ADD CONSTRAINT contacts_identity_check CHECK (wa_id IS NOT NULL OR bsuid IS NOT NULL OR (email IS NOT NULL AND email <> ''))");

        app(TenantContext::class)->bypass(fn () => DB::statement(<<<'SQL'
            UPDATE contacts c SET lifecycle = CASE
                WHEN EXISTS (SELECT 1 FROM enquiries e JOIN crm_stages s ON s.id = e.stage_id WHERE e.contact_id = c.id AND s."group" = 'won') THEN 'customer'
                WHEN EXISTS (SELECT 1 FROM enquiries e JOIN crm_stages s ON s.id = e.stage_id WHERE e.contact_id = c.id AND s."group" = 'open'
                    AND s.position > (SELECT min(position) FROM crm_stages f WHERE f.tenant_id = c.tenant_id AND f."group" = 'open' AND f.archived_at IS NULL)) THEN 'opportunity'
                WHEN NOT EXISTS (SELECT 1 FROM enquiries e WHERE e.contact_id = c.id)
                    AND (SELECT r.reason FROM conversation_resolutions r WHERE r.contact_id = c.id AND r.reopened_at IS NULL ORDER BY r.resolved_at DESC LIMIT 1) IN ('not_lead', 'no_action') THEN 'other'
                WHEN EXISTS (SELECT 1 FROM enquiries e WHERE e.contact_id = c.id) OR c.last_inbound_at IS NOT NULL THEN 'lead'
                WHEN c.consent_state = 'opted_in' THEN 'subscriber'
                ELSE 'lead' END
        SQL));
    }

    public function down(): void
    {
        Schema::table('contacts', fn (Blueprint $table) => $table->dropColumn('lifecycle'));
        DB::unprepared('ALTER TABLE contacts DROP CONSTRAINT IF EXISTS contacts_identity_check');
        DB::unprepared('ALTER TABLE contacts ADD CONSTRAINT contacts_identity_check CHECK (wa_id IS NOT NULL OR bsuid IS NOT NULL) NOT VALID');
    }
};
