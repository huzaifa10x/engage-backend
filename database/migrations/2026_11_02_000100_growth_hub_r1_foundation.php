<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Growth Hub R1 foundation (Technical Specification v1.2 §9, Blueprint v1.4).
 *
 * Contacts & CRM stays the only lead record: Growth Hub adds evidence next to it and a small
 * projection on the contact (original source — never overwritten —, latest touch, first tracked
 * enquiry). Website tracking tables (sites, number mappings, click events, references, pending
 * references, rollups, health) are created now and filled from Sprint 3. Every table is
 * workspace-scoped with row-level security.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('growth_sites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('domain', 253);
            $table->string('display_name', 120)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('site_key_hash', 128)->unique();
            $table->timestampTz('verified_at')->nullable();
            $table->string('verification_method', 20)->nullable();   // sdk_challenge | dns
            $table->string('consent_mode', 20)->default('click_only'); // click_only (default) | consent
            $table->boolean('session_landing_mode')->default(true);    // multi-page campaign memory (sessionStorage)
            $table->boolean('enabled')->default(false);
            $table->integer('config_version')->default(1);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'domain']);
        });

        Schema::create('growth_site_numbers', function (Blueprint $table) {
            $table->uuid('mapping_id');
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('site_id')->constrained('growth_sites')->cascadeOnDelete();
            $table->foreignUuid('phone_number_id')->constrained('phone_numbers')->cascadeOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['site_id', 'mapping_id']);
            $table->unique('mapping_id');
        });

        Schema::create('growth_click_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('site_id');
            $table->uuid('client_event_id');
            $table->uuid('mapping_id');
            $table->binary('payload_hash');
            $table->timestampTz('event_time');
            $table->timestampTz('client_occurred_at')->nullable();
            $table->timestampTz('received_at')->useCurrent();
            $table->string('page_path', 512)->nullable();
            $table->jsonb('campaign_json')->default(DB::raw("'{}'::jsonb"));
            $table->string('campaign_params_origin', 20);              // current_page | session_landing
            $table->string('collection_mode', 20);
            $table->string('status', 20);                              // registered | filtered | matched | expired
            $table->string('filtered_reason', 40)->nullable();
            $table->unique(['site_id', 'client_event_id']);
            $table->index(['tenant_id', 'received_at']);
        });

        Schema::create('growth_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('site_id');
            $table->uuid('click_id')->unique();
            $table->foreign('click_id')->references('id')->on('growth_click_events')->cascadeOnDelete();
            $table->binary('token_digest');
            $table->uuid('destination_phone_number_id');
            $table->timestampTz('issued_at');
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->string('consumed_message_id', 191)->nullable();
            $table->string('consumed_sender_wa_id', 64)->nullable();
            $table->unique(['tenant_id', 'token_digest']);
        });

        Schema::create('growth_pending_references', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('destination_phone_number_id');
            $table->string('wa_message_id', 191);
            $table->binary('token_digest');
            $table->string('sender_wa_id', 64);
            $table->timestampTz('provider_message_time');
            $table->timestampTz('received_at')->useCurrent();
            $table->timestampTz('retry_until');
            $table->string('status', 20)->default('pending');
            $table->primary(['tenant_id', 'destination_phone_number_id', 'wa_message_id']);
        });
        DB::statement("CREATE INDEX growth_pending_token_idx ON growth_pending_references (tenant_id, destination_phone_number_id, token_digest) WHERE status = 'pending'");

        Schema::create('growth_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('crm_contact_id');                            // contacts.id — the CRM record
            $table->uuid('message_id')->nullable();                    // messages.id of the inbound message
            $table->string('wa_message_id', 191);
            $table->uuid('click_id')->nullable();                      // website evidence only
            $table->string('evidence_type', 30);                       // meta_ctwa | website_reference | website_unregistered
            $table->string('confidence', 30);                          // confirmed | identity_unverified | unconfirmed
            $table->string('source', 30);                              // meta_ad | google_ads | organic | direct | website | …
            $table->jsonb('provider_fields')->default(DB::raw("'{}'::jsonb"));
            $table->boolean('conflict')->default(false);               // Meta ad and website code on the same message
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'crm_contact_id', 'occurred_at']);
            $table->index(['tenant_id', 'occurred_at']);
        });
        // CTWA evidence has no click: one row per message and type. Website evidence: per click.
        DB::statement('CREATE UNIQUE INDEX growth_evidence_message_unique ON growth_evidence (tenant_id, wa_message_id, evidence_type) WHERE click_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX growth_evidence_click_unique ON growth_evidence (tenant_id, wa_message_id, evidence_type, click_id) WHERE click_id IS NOT NULL');

        Schema::create('growth_daily_rollups', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('site_id');
            $table->date('local_date');
            $table->string('source_bucket', 40);
            $table->bigInteger('accepted_clicks')->default(0);
            $table->bigInteger('filtered_clicks')->default(0);
            $table->bigInteger('expired_unmatched')->default(0);
            $table->bigInteger('confirmed_reference_matches')->default(0);
            $table->primary(['tenant_id', 'site_id', 'local_date', 'source_bucket']);
        });

        Schema::create('growth_site_health', function (Blueprint $table) {
            $table->uuid('site_id')->primary();
            $table->foreign('site_id')->references('id')->on('growth_sites')->cascadeOnDelete();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->timestampTz('sdk_last_seen_at')->nullable();
            $table->timestampTz('last_click_at')->nullable();
            $table->timestampTz('last_match_at')->nullable();
            $table->string('last_error_code', 40)->nullable();
            $table->integer('config_version')->nullable();
        });

        foreach (['growth_sites', 'growth_site_numbers', 'growth_click_events', 'growth_references', 'growth_pending_references', 'growth_evidence', 'growth_daily_rollups', 'growth_site_health'] as $name) {
            TenantSchema::enableRls($name);
        }

        // The CRM projection: written only by Growth Hub, read everywhere the contact is shown.
        Schema::table('contacts', function (Blueprint $table) {
            $table->jsonb('acquisition_original')->nullable();        // immutable once set
            $table->jsonb('acquisition_latest')->nullable();          // latest confirmed touch
            $table->timestampTz('first_tracked_enquiry_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', fn (Blueprint $table) => $table->dropColumn(['acquisition_original', 'acquisition_latest', 'first_tracked_enquiry_at']));
        foreach (['growth_site_health', 'growth_daily_rollups', 'growth_evidence', 'growth_pending_references', 'growth_references', 'growth_click_events', 'growth_site_numbers', 'growth_sites'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
