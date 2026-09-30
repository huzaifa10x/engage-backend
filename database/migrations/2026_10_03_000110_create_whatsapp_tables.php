<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Meta objects modelled explicitly (blueprint "Full schema reference"). Meta IDs are strings,
     * exactly as Meta returns them. The business portfolio is collapsed onto waba_accounts
     * (meta_business_id / business_name) — a tenant never acts on the portfolio itself.
     */
    public function up(): void
    {
        Schema::create('meta_access_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('secret_id')->constrained('secrets')->restrictOnDelete();
            $table->string('token_type', 32)->default('business');     // business integration system user
            $table->string('meta_app_id', 32);
            $table->string('meta_subject_id', 32)->nullable();          // debug_token user_id (system user)
            $table->jsonb('scopes')->nullable();
            $table->jsonb('granular_scopes')->nullable();
            $table->timestampTz('expires_at')->nullable();              // NULL = never expires
            $table->timestampTz('data_access_expires_at')->nullable();
            $table->timestampTz('last_validated_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });
        TenantSchema::tenantKey('meta_access_tokens');
        TenantSchema::enableRls('meta_access_tokens');

        Schema::create('waba_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('waba_id', 32)->unique();                    // one WABA → one tenant
            $table->uuid('access_token_id')->nullable();
            $table->string('name')->nullable();
            $table->string('meta_business_id', 32)->nullable();
            $table->string('business_name')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('timezone_id', 8)->nullable();
            $table->string('message_template_namespace', 64)->nullable();
            $table->string('account_review_status', 32)->nullable();
            $table->string('ban_state', 32)->nullable();
            $table->jsonb('health_status')->nullable();
            $table->jsonb('capabilities')->nullable();                 // business_capability_update
            $table->string('status', 16)->default('connected');
            $table->boolean('is_subscribed_to_webhooks')->default(false);
            $table->timestampTz('connected_at')->nullable();
            $table->timestampTz('disconnected_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status']);
        });
        TenantSchema::tenantKey('waba_accounts');
        TenantSchema::tenantForeign('waba_accounts', 'access_token_id', 'meta_access_tokens');
        TenantSchema::check('waba_accounts', 'waba_accounts_status_check', "status IN ('connected','disconnected')");
        TenantSchema::enableRls('waba_accounts');

        Schema::create('phone_numbers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('waba_account_id');
            $table->string('phone_number_id', 32)->unique();
            $table->string('display_phone_number', 32)->nullable();
            $table->string('e164', 20)->nullable();
            $table->string('verified_name')->nullable();
            $table->string('name_status', 32)->nullable();
            $table->string('quality_rating', 16)->nullable();          // GREEN / YELLOW / RED / UNKNOWN
            $table->string('messaging_limit_tier', 32)->nullable();
            $table->string('throughput_level', 32)->nullable();
            $table->string('code_verification_status', 32)->nullable();
            $table->string('platform_type', 32)->nullable();           // CLOUD_API / ON_PREMISE / NOT_APPLICABLE
            $table->boolean('is_official_business_account')->default(false);
            $table->boolean('is_on_biz_app')->default(false);
            $table->string('status', 16)->default('pending');
            $table->string('onboarding_type', 16)->default('new_number');
            $table->string('coexistence_status', 16)->default('none');
            $table->timestampTz('app_sync_started_at')->nullable();
            $table->timestampTz('app_sync_expires_at')->nullable();
            $table->unsignedSmallInteger('max_mps')->default(80);
            $table->jsonb('capabilities')->nullable();
            $table->uuid('pin_secret_id')->nullable();
            $table->timestampTz('registered_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status']);
            $table->index('quality_rating');
        });
        TenantSchema::tenantKey('phone_numbers');
        TenantSchema::tenantForeign('phone_numbers', 'waba_account_id', 'waba_accounts', 'CASCADE');
        TenantSchema::check('phone_numbers', 'phone_numbers_status_check', "status IN ('pending','connected','disconnected')");
        TenantSchema::check('phone_numbers', 'phone_numbers_onboarding_type_check', "onboarding_type IN ('new_number','migrated','coexistence')");
        TenantSchema::check('phone_numbers', 'phone_numbers_coexistence_status_check',
            "coexistence_status IN ('none','sync_pending','history_syncing','synced','sync_failed','offboarded')");
        TenantSchema::enableRls('phone_numbers');

        // Blueprint "user_number_access": keyed by membership so it dies with the membership.
        Schema::create('user_number_access', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('membership_id');
            $table->uuid('phone_number_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['membership_id', 'phone_number_id']);
            $table->index(['tenant_id', 'phone_number_id']);
        });
        TenantSchema::tenantForeign('user_number_access', 'membership_id', 'tenant_memberships', 'CASCADE');
        TenantSchema::tenantForeign('user_number_access', 'phone_number_id', 'phone_numbers', 'CASCADE');
        TenantSchema::enableRls('user_number_access');

        // Blueprint "embedded_signup_events": one row per attempt, step history in `steps`.
        Schema::create('embedded_signup_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('flow', 16)->default('standard');           // standard | coexistence
            $table->string('status', 16)->default('started');
            $table->string('event', 48)->nullable();                   // FINISH / FINISH_ONLY_WABA / FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING / CANCEL / ERROR
            $table->string('current_step', 64)->nullable();            // abandoned screen (CANCEL)
            $table->string('meta_session_id', 64)->nullable();
            $table->string('meta_user_id', 32)->nullable();            // FB login app-scoped id (data deletion)
            $table->string('waba_id', 32)->nullable();
            $table->string('phone_number_id', 32)->nullable();
            $table->string('meta_business_id', 32)->nullable();
            $table->uuid('waba_account_id')->nullable();
            $table->string('error_code', 32)->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('steps')->nullable();
            $table->jsonb('session_payload')->nullable();              // sanitized; never the code or token
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'created_at']);
            $table->index('meta_user_id');
        });
        TenantSchema::check('embedded_signup_attempts', 'embedded_signup_attempts_status_check',
            "status IN ('started','exchanging','provisioning','completed','failed','cancelled')");
        TenantSchema::enableRls('embedded_signup_attempts');

        Schema::create('coexistence_sync_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('phone_number_id');
            $table->string('sync_type', 32);                           // smb_app_state_sync | history
            $table->string('request_id', 128)->nullable();
            $table->string('status', 16)->default('requested');
            $table->unsignedTinyInteger('phase')->nullable();
            $table->unsignedInteger('chunk_order')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedInteger('chunks_received')->default(0);
            $table->unsignedInteger('media_pending')->default(0);
            $table->string('error_code', 32)->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('requested_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['phone_number_id', 'sync_type']);          // Meta allows each sync once
        });
        TenantSchema::tenantForeign('coexistence_sync_jobs', 'phone_number_id', 'phone_numbers', 'CASCADE');
        TenantSchema::check('coexistence_sync_jobs', 'coexistence_sync_jobs_status_check',
            "status IN ('requested','in_progress','completed','declined','failed')");
        TenantSchema::enableRls('coexistence_sync_jobs');

        // Append-only history of quality / limit / throughput / review webhooks (health dashboard).
        Schema::create('quality_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('waba_account_id')->nullable();
            $table->uuid('phone_number_id')->nullable();
            $table->string('event_type', 48);
            $table->string('old_value', 64)->nullable();
            $table->string('new_value', 64)->nullable();
            $table->jsonb('payload');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['phone_number_id', 'occurred_at']);
        });
        TenantSchema::enableRls('quality_events');
    }

    public function down(): void
    {
        foreach (['quality_events', 'coexistence_sync_jobs', 'embedded_signup_attempts', 'user_number_access', 'phone_numbers', 'waba_accounts', 'meta_access_tokens'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
