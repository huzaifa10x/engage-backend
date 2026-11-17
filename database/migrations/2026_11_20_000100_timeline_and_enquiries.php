<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contact timeline and enquiries (batch 6a, item 0). Every later item writes to these.
 *
 *  - enquiries: one buying conversation of a contact (a rental in March, another in June), with its
 *    source, how sure that source is (verified / partial / unknown, never guessed), owner and stage.
 *  - timeline_events: one row per thing that happened to a contact, in UTC, with who caused it and
 *    a link to the record it came from. Rows are added, never edited (a trigger refuses UPDATE);
 *    corrections are new events. Kept per workspace by row-level security.
 *  - contacts.current_enquiry_id: the enquiry new events belong to.
 *  - commerce_orders.enquiry_id: the enquiry a store order (payment) belongs to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enquiries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('contact_id');
            $table->unsignedInteger('number');                         // 1, 2, 3 … per contact
            $table->uuid('conversation_id')->nullable();
            $table->timestampTz('started_at');
            $table->string('started_by', 10);                           // customer | agent | system
            $table->string('start_reason', 16);                         // first_message | quiet_period | ad_click | manual | backfill
            $table->string('source', 10)->default('direct');            // ad | link | website | direct
            $table->string('source_confidence', 10)->default('unknown'); // verified | partial | unknown
            $table->jsonb('source_detail')->nullable();                 // ad id, headline, link id and name, campaign label — never the click id itself
            $table->uuid('owner_membership_id')->nullable();
            $table->uuid('stage_id')->nullable();
            $table->timestampTz('last_activity_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'contact_id', 'number']);
            $table->index(['tenant_id', 'started_at']);
        });
        TenantSchema::enableRls('enquiries');
        TenantSchema::tenantKey('enquiries');
        TenantSchema::tenantForeign('enquiries', 'contact_id', 'contacts', 'CASCADE');
        TenantSchema::check('enquiries', 'enquiries_source_check', "source IN ('ad','link','website','direct')");
        TenantSchema::check('enquiries', 'enquiries_confidence_check', "source_confidence IN ('verified','partial','unknown')");
        TenantSchema::check('enquiries', 'enquiries_started_by_check', "started_by IN ('customer','agent','system')");

        Schema::create('timeline_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('contact_id');
            $table->uuid('enquiry_id')->nullable();
            $table->string('type', 40);
            $table->timestampTz('occurred_at');
            $table->string('actor', 10);                                // customer | agent | system
            $table->uuid('actor_membership_id')->nullable();
            $table->string('actor_name', 120)->nullable();              // as it was when it happened
            $table->string('subject_type', 40)->nullable();             // message | conversation | consent_event | stage_event | flow_session | form_submission | order | webhook_delivery | enquiry | …
            $table->uuid('subject_id')->nullable();
            $table->jsonb('data')->nullable();                          // a short summary for the history (no message text)
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'contact_id', 'occurred_at']);
            $table->index(['tenant_id', 'enquiry_id', 'occurred_at']);
            $table->index(['tenant_id', 'type', 'occurred_at']);
        });
        TenantSchema::enableRls('timeline_events');
        TenantSchema::tenantForeign('timeline_events', 'contact_id', 'contacts', 'CASCADE');
        TenantSchema::check('timeline_events', 'timeline_events_actor_check', "actor IN ('customer','agent','system')");
        // Added, never edited: corrections are new events. (Deleting a contact still removes its history.)
        DB::unprepared("CREATE OR REPLACE FUNCTION timeline_events_no_update() RETURNS trigger AS \$\$ BEGIN RAISE EXCEPTION 'timeline events are never edited; record a correction as a new event'; END \$\$ LANGUAGE plpgsql");
        DB::unprepared('CREATE TRIGGER timeline_events_append_only BEFORE UPDATE ON timeline_events FOR EACH ROW EXECUTE FUNCTION timeline_events_no_update()');

        Schema::table('contacts', function (Blueprint $table) {
            $table->uuid('current_enquiry_id')->nullable();
        });
        Schema::table('commerce_orders', function (Blueprint $table) {
            $table->uuid('enquiry_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('commerce_orders', fn (Blueprint $table) => $table->dropColumn('enquiry_id'));
        Schema::table('contacts', fn (Blueprint $table) => $table->dropColumn('current_enquiry_id'));
        DB::unprepared('DROP TRIGGER IF EXISTS timeline_events_append_only ON timeline_events');
        DB::unprepared('DROP FUNCTION IF EXISTS timeline_events_no_update()');
        Schema::dropIfExists('timeline_events');
        Schema::dropIfExists('enquiries');
    }
};
