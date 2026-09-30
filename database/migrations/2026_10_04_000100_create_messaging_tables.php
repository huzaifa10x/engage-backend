<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 messaging. Identity note: since April 2026 Meta identifies WhatsApp users by a
     * business-scoped user ID (BSUID, `user_id`) and may omit the phone number (`wa_id`) for users
     * with usernames — a contact therefore has a phone, a BSUID, or both.
     */
    public function up(): void
    {
        // Trigram index for inbox / contact search (trusted extension since PG13).
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('wa_id', 20)->nullable();                 // phone digits, no '+'
            $table->string('bsuid', 160)->nullable();                // e.g. US.13491208655302741918
            $table->string('parent_bsuid', 160)->nullable();
            $table->string('username', 64)->nullable();
            $table->string('profile_name', 190)->nullable();         // from WhatsApp
            $table->string('name', 190)->nullable();                 // set by the business
            $table->string('email', 190)->nullable();
            $table->jsonb('custom_fields')->nullable();           // API field: `attributes`
            $table->string('source', 16)->default('inbound');
            $table->string('consent_state', 16)->default('unknown');
            $table->timestampTz('opted_in_at')->nullable();
            $table->timestampTz('opted_out_at')->nullable();
            $table->boolean('marketing_opted_out')->default(false);  // user_preferences webhook
            $table->timestampTz('last_inbound_at')->nullable();
            $table->timestampTz('deleted_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'consent_state']);
        });
        TenantSchema::tenantKey('contacts');
        TenantSchema::check('contacts', 'contacts_identity_check', 'wa_id IS NOT NULL OR bsuid IS NOT NULL');
        TenantSchema::check('contacts', 'contacts_consent_check', "consent_state IN ('unknown','opted_in','opted_out')");
        TenantSchema::check('contacts', 'contacts_source_check', "source IN ('inbound','manual','api','import','app_sync','echo','history')");
        DB::statement('CREATE UNIQUE INDEX contacts_tenant_wa_id_unique ON contacts (tenant_id, wa_id) WHERE wa_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX contacts_tenant_bsuid_unique ON contacts (tenant_id, bsuid) WHERE bsuid IS NOT NULL');
        DB::statement("CREATE INDEX contacts_search_idx ON contacts USING gin ((coalesce(name,'') || ' ' || coalesce(profile_name,'') || ' ' || coalesce(wa_id,'')) gin_trgm_ops)");
        TenantSchema::enableRls('contacts');

        Schema::create('consent_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('contact_id');
            $table->string('action', 24);                            // opted_in | opted_out | marketing_opted_out | marketing_opted_in
            $table->string('source', 24);                            // keyword | agent | api | import | meta_preference
            $table->string('detail', 190)->nullable();
            $table->uuid('message_id')->nullable();
            $table->uuid('membership_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['contact_id', 'created_at']);
        });
        TenantSchema::tenantForeign('consent_events', 'contact_id', 'contacts', 'CASCADE');
        TenantSchema::enableRls('consent_events');

        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('phone_number_id');
            $table->uuid('contact_id');
            $table->string('status', 16)->default('open');
            $table->uuid('assigned_membership_id')->nullable();
            $table->unsignedInteger('unread_count')->default(0);
            $table->timestampTz('last_message_at')->nullable();
            $table->string('last_message_preview', 200)->nullable();
            $table->string('last_message_direction', 8)->nullable();
            $table->timestampTz('last_inbound_at')->nullable();
            $table->timestampTz('window_expires_at')->nullable();    // customer service window
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['phone_number_id', 'contact_id']);      // one thread per number × contact
            $table->index(['tenant_id', 'status', 'last_message_at']);
            $table->index(['tenant_id', 'assigned_membership_id', 'last_message_at']);
        });
        TenantSchema::tenantKey('conversations');
        TenantSchema::tenantForeign('conversations', 'phone_number_id', 'phone_numbers', 'CASCADE');
        TenantSchema::tenantForeign('conversations', 'contact_id', 'contacts', 'CASCADE');
        TenantSchema::check('conversations', 'conversations_status_check', "status IN ('open','closed')");
        TenantSchema::enableRls('conversations');

        Schema::create('media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('direction', 8);                          // inbound | outbound
            $table->string('meta_media_id', 64)->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->string('sha256', 128)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('filename', 255)->nullable();
            $table->string('disk', 32)->nullable();
            $table->string('path', 512)->nullable();
            $table->string('status', 16)->default('pending');        // pending | ready | failed
            $table->text('error')->nullable();
            $table->uuid('uploaded_to_phone_number_id')->nullable(); // Meta media IDs are per number
            $table->timestampTz('meta_media_expires_at')->nullable();
            $table->uuid('created_by_membership_id')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'created_at']);
        });
        TenantSchema::tenantKey('media');
        TenantSchema::check('media', 'media_status_check', "status IN ('pending','ready','failed')");
        TenantSchema::enableRls('media');

        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('conversation_id');
            $table->uuid('phone_number_id');
            $table->uuid('contact_id');
            $table->string('direction', 8);                          // inbound | outbound
            $table->string('origin', 16);                            // customer | agent | api | campaign | automation | app_echo | history
            $table->string('type', 24);
            $table->string('status', 16);
            $table->string('wamid', 191)->nullable();
            $table->string('context_wamid', 191)->nullable();        // replied-to message
            $table->text('body')->nullable();                        // text / caption / searchable summary
            $table->jsonb('content')->nullable();                    // type-specific payload
            $table->uuid('media_id')->nullable();
            $table->jsonb('template')->nullable();
            $table->string('error_code', 32)->nullable();
            $table->string('error_title', 255)->nullable();
            $table->jsonb('error_details')->nullable();
            $table->jsonb('pricing')->nullable();
            $table->uuid('sent_by_membership_id')->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->timestampTz('meta_timestamp')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('edited_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });
        TenantSchema::tenantKey('messages');
        TenantSchema::tenantForeign('messages', 'conversation_id', 'conversations', 'CASCADE');
        TenantSchema::tenantForeign('messages', 'phone_number_id', 'phone_numbers', 'CASCADE');
        TenantSchema::tenantForeign('messages', 'contact_id', 'contacts', 'CASCADE');
        TenantSchema::tenantForeign('messages', 'media_id', 'media', 'RESTRICT');
        TenantSchema::check('messages', 'messages_direction_check', "direction IN ('inbound','outbound')");
        TenantSchema::check('messages', 'messages_status_check',
            "status IN ('queued','accepted','sent','delivered','read','failed','received','deleted')");
        // wamid is globally unique at Meta; this is also our webhook/import idempotency key.
        DB::statement('CREATE UNIQUE INDEX messages_wamid_unique ON messages (wamid) WHERE wamid IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX messages_idempotency_unique ON messages (tenant_id, idempotency_key) WHERE idempotency_key IS NOT NULL');
        TenantSchema::enableRls('messages');

        Schema::create('message_status_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('message_id');
            $table->string('status', 16);
            $table->timestampTz('occurred_at');
            $table->jsonb('payload')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['message_id', 'occurred_at']);
        });
        TenantSchema::tenantForeign('message_status_events', 'message_id', 'messages', 'CASCADE');
        TenantSchema::enableRls('message_status_events');
    }

    public function down(): void
    {
        foreach (['message_status_events', 'messages', 'media', 'conversations', 'consent_events', 'contacts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
