<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('plan_version_id')->constrained('plan_versions')->restrictOnDelete();
            $table->string('status', 16);
            $table->string('provider', 16)->default('manual');
            $table->string('provider_customer_id', 191)->nullable();
            $table->string('provider_subscription_id', 191)->nullable();
            $table->string('billing_interval', 8)->nullable();
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('current_period_start')->nullable();
            $table->timestampTz('current_period_end')->nullable();
            $table->timestampTz('cancel_at')->nullable();
            $table->timestampTz('canceled_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'trial_ends_at']);
        });

        TenantSchema::check('subscriptions', 'subscriptions_status_check', "status IN ('trialing','active','past_due','canceled','expired')");
        TenantSchema::check('subscriptions', 'subscriptions_provider_check', "provider IN ('manual','stripe')");
        TenantSchema::tenantKey('subscriptions');

        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX subscriptions_one_live_per_tenant ON subscriptions (tenant_id)
                WHERE status IN ('trialing','active','past_due');
            CREATE UNIQUE INDEX subscriptions_provider_subscription_unique ON subscriptions (provider, provider_subscription_id)
                WHERE provider_subscription_id IS NOT NULL;
        SQL);

        TenantSchema::enableRls('subscriptions');

        Schema::create('subscription_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('subscription_id');
            $table->string('type', 64);
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->jsonb('payload')->nullable();
            $table->string('idempotency_key', 191)->nullable()->unique(); // provider event id
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at')->nullable();

            $table->index(['tenant_id', 'subscription_id', 'occurred_at']);
        });

        TenantSchema::tenantForeign('subscription_events', 'subscription_id', 'subscriptions', 'CASCADE');
        TenantSchema::enableRls('subscription_events');

        Schema::create('tenant_entitlement_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('feature_id')->constrained('features')->restrictOnDelete();
            $table->boolean('enabled')->nullable();       // NULL = inherit
            $table->bigInteger('limit_value')->nullable(); // NULL = inherit (see `unlimited`)
            $table->boolean('unlimited')->default(false);
            $table->jsonb('config')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->foreignUuid('created_by_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'feature_id']);
        });

        // Tenants can read their overrides; only the platform (bypass) can write them.
        TenantSchema::enableRls(
            'tenant_entitlement_overrides',
            using: TenantSchema::DEFAULT_POLICY,
            check: 'engage_rls_bypass()',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_entitlement_overrides');
        Schema::dropIfExists('subscription_events');
        Schema::dropIfExists('subscriptions');
    }
};
