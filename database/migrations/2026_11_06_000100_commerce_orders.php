<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp Engagement Plan v4, step D: which chats became orders (WooCommerce).
 *
 *  integrations.plugin_secret   encrypted; signs what the "10X Engage for WooCommerce" plugin sends
 *  commerce_link_tokens         the signed chat-to-order token (?xl=…) added to store links sent from a
 *                               chat; tied to one conversation, valid ~14 days, attaches one order once
 *  commerce_orders              one row per store order, always its latest state; how it was matched
 *                               (link | review | phone | unavailable | none) and the two sources kept on
 *                               it: the contact's original source and the source of the chat the link
 *                               came from. WooCommerce's own order source is kept apart.
 *  commerce_order_items         line items (products sold by source)
 *  commerce_order_events        every change the store reported, once (order + event + time)
 *
 * Orders stay when a store is disconnected (no foreign key to integrations): revenue already
 * reported must not disappear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->text('plugin_secret')->nullable();
        });

        Schema::create('commerce_link_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->binary('digest');                                   // HMAC of the token; the token itself is never stored
            $table->uuid('conversation_id');
            $table->uuid('contact_id');
            $table->uuid('message_id')->nullable();
            $table->string('sent_by', 16);                              // agent | bot
            $table->uuid('sent_by_membership_id')->nullable();
            $table->string('url', 2048);
            $table->jsonb('conversation_source')->nullable();           // the chat's own source when the link was sent
            $table->timestampTz('expires_at');
            $table->uuid('used_order_id')->nullable();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'contact_id', 'created_at']);
        });
        DB::statement('CREATE UNIQUE INDEX commerce_link_tokens_digest_unique ON commerce_link_tokens (digest)');
        TenantSchema::enableRls('commerce_link_tokens');

        Schema::create('commerce_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('integration_id');
            $table->string('store_name', 120);
            $table->string('external_id', 64);
            $table->string('number', 64);
            $table->string('status', 24);                               // WooCommerce: pending | on-hold | processing | completed | cancelled | refunded | failed | …
            $table->string('currency', 3);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('refunded', 14, 2)->default(0);
            $table->decimal('net', 14, 2)->default(0);                  // revenue: paid total minus refunds; 0 unless paid
            $table->boolean('paid')->default(false);
            $table->unsignedInteger('items_count')->default(0);
            $table->string('billing_phone', 20)->nullable();            // international digits
            $table->string('billing_name', 120)->nullable();
            $table->uuid('contact_id')->nullable();
            $table->uuid('conversation_id')->nullable();
            $table->uuid('link_token_id')->nullable();
            $table->string('match', 16)->default('none');              // link | review | phone | unavailable | none
            $table->string('match_note', 200)->nullable();
            $table->boolean('match_reviewed')->default(false);          // a person decided the review: never re-matched
            $table->jsonb('original_source')->nullable();               // the contact's original source (R1)
            $table->jsonb('conversation_source')->nullable();           // the source of the chat the link came from
            $table->jsonb('store_source')->nullable();                  // WooCommerce's own order attribution, untouched
            $table->timestampTz('first_chat_at')->nullable();
            $table->timestampTz('placed_at');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('refunded_at')->nullable();
            $table->timestampTz('store_updated_at')->nullable();        // only a newer state replaces the stored one
            $table->timestampTz('stage_moved_at')->nullable();
            $table->timestampsTz();

            $table->unique(['integration_id', 'external_id']);
            $table->index(['tenant_id', 'placed_at']);
            $table->index(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'match', 'placed_at']);
        });
        TenantSchema::enableRls('commerce_orders');
        TenantSchema::tenantKey('commerce_orders');
        TenantSchema::check('commerce_orders', 'commerce_orders_match_check', "match IN ('link','review','phone','unavailable','none')");

        Schema::create('commerce_order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('order_id');
            $table->string('product_id', 64)->nullable();
            $table->string('sku', 100)->nullable();
            $table->string('name', 255);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('total', 14, 2)->default(0);

            $table->index(['order_id']);
        });
        TenantSchema::enableRls('commerce_order_items');
        TenantSchema::tenantForeign('commerce_order_items', 'order_id', 'commerce_orders', 'CASCADE');

        Schema::create('commerce_order_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('integration_id');
            $table->string('external_id', 64);
            $table->string('event', 20);                                // created | paid | completed | refunded | cancelled | failed | updated
            $table->string('status', 24)->nullable();
            $table->decimal('total', 14, 2)->nullable();
            $table->decimal('refunded', 14, 2)->nullable();
            $table->string('via', 10)->default('plugin');               // plugin | webhook | catch_up
            $table->timestampTz('occurred_at');
            $table->timestampTz('received_at')->useCurrent();

            // Retries and repeats are ignored: one row per order, event and time.
            $table->unique(['integration_id', 'external_id', 'event', 'occurred_at']);
            $table->index(['tenant_id', 'received_at']);
        });
        TenantSchema::enableRls('commerce_order_events');
        TenantSchema::tenantForeign('commerce_order_events', 'integration_id', 'integrations', 'CASCADE');
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_order_events');
        Schema::dropIfExists('commerce_order_items');
        Schema::dropIfExists('commerce_orders');
        Schema::dropIfExists('commerce_link_tokens');
        Schema::table('integrations', fn (Blueprint $table) => $table->dropColumn('plugin_secret'));
    }
};
