<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Integrations module: connected stores (Shopify, WooCommerce), the message each store event
 * sends, and a log of what happened to every event. Also lets webhook endpoints be created
 * through the public API (Zapier, Make), and lets contacts record that they came from a store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('provider', 24);                 // shopify | woocommerce
            $table->string('public_id', 40)->unique();      // random; part of the store's webhook URL, never the row id
            $table->string('name', 120);
            $table->string('external_id', 255);             // shop domain (Shopify) or store address (WooCommerce)
            $table->string('status', 16)->default('active'); // active | paused
            $table->uuid('secret_id')->nullable();          // credentials, held in the secret store
            $table->text('webhook_secret')->nullable();     // encrypted; verifies what the store sends us
            $table->jsonb('settings')->nullable();
            $table->timestampTz('last_event_at')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->uuid('connected_by_membership_id')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'provider']);
            // A store reports to exactly one workspace: its events carry only the store's identity.
            $table->unique(['provider', 'external_id']);
        });
        TenantSchema::enableRls('integrations');
        TenantSchema::tenantKey('integrations');

        Schema::create('integration_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('integration_id');
            $table->string('event', 32);                    // order_created | order_fulfilled | order_cancelled | checkout_abandoned
            $table->boolean('enabled')->default(false);
            $table->uuid('phone_number_id')->nullable();    // send from; null = the workspace's only connected number
            $table->string('template_name', 512)->nullable();
            $table->string('template_language', 15)->nullable();
            $table->jsonb('variables')->nullable();         // {header: [..], body: [..], buttons: {index: ..}} with {{field}} placeholders
            $table->unsignedInteger('delay_minutes')->default(0);
            $table->timestampsTz();

            $table->unique(['integration_id', 'event']);
        });
        TenantSchema::enableRls('integration_rules');
        TenantSchema::tenantForeign('integration_rules', 'integration_id', 'integrations', 'CASCADE');

        Schema::create('integration_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('integration_id');
            $table->string('event', 32);
            $table->string('external_id', 191);             // the order or checkout this is about
            $table->string('status', 16)->default('pending'); // pending | sent | skipped | failed | cancelled
            $table->string('detail', 300)->nullable();      // why it was skipped or failed, in plain words
            $table->string('phone', 32)->nullable();
            $table->uuid('contact_id')->nullable();
            $table->uuid('message_id')->nullable();
            $table->jsonb('data')->nullable();              // the values available to the template
            $table->timestampTz('due_at')->nullable();      // when a delayed message (abandoned checkout) should go
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            // The same order event from the store is handled once, however often the store repeats it.
            $table->unique(['integration_id', 'event', 'external_id']);
            $table->index(['status', 'due_at']);
            $table->index(['integration_id', 'created_at']);
        });
        TenantSchema::enableRls('integration_events');
        TenantSchema::tenantForeign('integration_events', 'integration_id', 'integrations', 'CASCADE');

        // Webhook endpoints created through the API (Zapier, Make) are told apart from the ones typed in the portal.
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->string('source', 16)->default('portal'); // portal | api
            $table->uuid('api_key_id')->nullable();
        });

        DB::statement('ALTER TABLE contacts DROP CONSTRAINT IF EXISTS contacts_source_check');
        TenantSchema::check('contacts', 'contacts_source_check', "source IN ('inbound','manual','api','import','app_sync','echo','history','shopify','woocommerce')");
    }

    public function down(): void
    {
        Schema::table('webhook_endpoints', function (Blueprint $table) {
            $table->dropColumn(['source', 'api_key_id']);
        });
        Schema::dropIfExists('integration_events');
        Schema::dropIfExists('integration_rules');
        Schema::dropIfExists('integrations');
    }
};
