<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing Messages API (MM API) for WhatsApp.
 * - waba_accounts.mm_*: our mirror of Meta's onboarding state for each WhatsApp Business Account.
 *   Written only by the status sync, the enrollment request and the account_update webhook.
 * - mm_onboarding_events: append-only history of every change ("why did this account flip?").
 * - messages.send_route: which API sent the message — mm_api, cloud_api, or mm_fallback (sent to the
 *   MM API, delivered by Meta through the Cloud API because onboarding was not complete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('waba_accounts', function (Blueprint $table) {
            $table->string('mm_status', 20)->default('not_started');  // not_started | eligible | request_sent | onboarded | not_eligible
            $table->string('mm_meta_status', 40)->nullable();          // WABA marketing_messages_onboarding_status, as Meta returned it
            $table->string('mm_terms_status', 40)->nullable();         // owner business Terms of Service status (NOT_STARTED, REQUEST_SENT, TERM_OF_SERVICE_SIGNED)
            $table->string('mm_consent_source', 20)->nullable();       // embedded_signup | backfill_batch | client_prompt | meta
            $table->timestampTz('mm_requested_at')->nullable();         // when we asked the business to accept the terms
            $table->string('mm_request_error', 255)->nullable();       // why Meta refused the request (the business then accepts in WhatsApp Manager)
            $table->timestampTz('mm_onboarded_at')->nullable();
            $table->timestampTz('mm_status_synced_at')->nullable();
        });

        Schema::create('mm_onboarding_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('waba_account_id');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('trigger', 20);                              // embedded_signup | backfill | webhook | sync | request
            $table->jsonb('raw_payload')->nullable();
            $table->timestampTz('created_at');

            $table->index(['waba_account_id', 'created_at']);
        });
        TenantSchema::enableRls('mm_onboarding_events');

        Schema::table('messages', function (Blueprint $table) {
            $table->string('send_route', 12)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn('send_route'));
        Schema::dropIfExists('mm_onboarding_events');
        Schema::table('waba_accounts', fn (Blueprint $table) => $table->dropColumn([
            'mm_status', 'mm_meta_status', 'mm_terms_status', 'mm_consent_source', 'mm_requested_at', 'mm_request_error', 'mm_onboarded_at', 'mm_status_synced_at',
        ]));
    }
};
