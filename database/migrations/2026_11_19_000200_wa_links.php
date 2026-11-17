<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * wa.me links, QR codes and website buttons with a source (batch 5, item 2).
 *
 *  - wa_links: per number, a source name ("Shop window", "Instagram bio", "Website button"), the
 *    pre-filled text and a short code written at its end ("… (Ref: EX-7PK9M2)"), so the chat's first
 *    message says where it came from. A website button also keeps its position and label.
 *  - wa_link_chats: each chat started from a link (one row per message carrying the code).
 *  - contacts.source_detail: the link's name on a contact the link brought in (source "wa_link").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('phone_number_id');
            $table->string('kind', 16)->default('link');                   // link | website_button
            $table->string('name', 80);                                     // the source name
            $table->string('code', 12);                                     // EX-xxxxxx, unique in the workspace
            $table->string('message', 500);                                 // pre-filled text, without the code
            $table->boolean('asks_consent')->default(false);                // the pre-filled text asks for marketing messages
            $table->jsonb('button')->nullable();                            // website button: position, label
            $table->uuid('created_by_membership_id')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'phone_number_id']);
        });
        TenantSchema::enableRls('wa_links');
        TenantSchema::tenantKey('wa_links');
        TenantSchema::tenantForeign('wa_links', 'phone_number_id', 'phone_numbers', 'CASCADE');
        TenantSchema::check('wa_links', 'wa_links_kind_check', "kind IN ('link','website_button')");

        Schema::create('wa_link_chats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('wa_link_id');
            $table->uuid('contact_id');
            $table->uuid('conversation_id')->nullable();
            $table->uuid('message_id');
            $table->boolean('new_contact')->default(false);                 // the link brought this contact in
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'message_id']);
            $table->index(['tenant_id', 'wa_link_id', 'created_at']);
        });
        TenantSchema::enableRls('wa_link_chats');
        TenantSchema::tenantForeign('wa_link_chats', 'wa_link_id', 'wa_links', 'CASCADE');

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('source_detail', 80)->nullable();
        });
        DB::statement('ALTER TABLE contacts DROP CONSTRAINT IF EXISTS contacts_source_check');
        TenantSchema::check('contacts', 'contacts_source_check', "source IN ('inbound','manual','api','import','app_sync','echo','history','shopify','woocommerce','wa_link')");
    }

    public function down(): void
    {
        DB::statement("UPDATE contacts SET source = 'inbound' WHERE source = 'wa_link'");
        DB::statement('ALTER TABLE contacts DROP CONSTRAINT IF EXISTS contacts_source_check');
        TenantSchema::check('contacts', 'contacts_source_check', "source IN ('inbound','manual','api','import','app_sync','echo','history','shopify','woocommerce')");
        Schema::table('contacts', fn (Blueprint $table) => $table->dropColumn('source_detail'));
        Schema::dropIfExists('wa_link_chats');
        Schema::dropIfExists('wa_links');
    }
};
