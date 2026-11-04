<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Click-to-WhatsApp ads seen in a workspace (one row per Meta ad ID = referral.source_id), so leads
 * can be grouped by ad and an ad can be tagged as running on one platform only (Tracking Setup):
 * the proof for "Meta ad · Instagram / Facebook". Evidence also records the conversation and number
 * it came in on, so the referral is kept against the contact and the conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('growth_meta_ads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('source_id', 64);                    // Meta's ad (or post) ID
            $table->string('source_type', 20)->nullable();      // ad | post
            $table->string('headline', 512)->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->string('platform', 20)->nullable();         // instagram | facebook | null = runs on both / not set
            $table->uuid('platform_set_by_membership_id')->nullable();
            $table->timestampTz('platform_set_at')->nullable();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->unique(['tenant_id', 'source_id']);
        });
        TenantSchema::enableRls('growth_meta_ads');
        TenantSchema::check('growth_meta_ads', 'growth_meta_ads_platform_check', "platform IS NULL OR platform IN ('instagram','facebook')");

        Schema::table('growth_evidence', function (Blueprint $table) {
            $table->uuid('conversation_id')->nullable();
            $table->uuid('phone_number_id')->nullable();
            $table->index(['tenant_id', 'conversation_id']);
        });

        // Existing evidence: conversation and number from its message; ads from its referral.
        app(TenantContext::class)->bypass(function (): void {
            DB::statement('UPDATE growth_evidence e SET conversation_id = m.conversation_id, phone_number_id = m.phone_number_id FROM messages m WHERE m.id = e.message_id AND e.conversation_id IS NULL');
            DB::statement(<<<'SQL'
            INSERT INTO growth_meta_ads (id, tenant_id, source_id, source_type, headline, source_url, first_seen_at, last_seen_at)
            SELECT gen_random_uuid(), tenant_id, provider_fields->'referral'->>'source_id',
                   (array_agg(provider_fields->'referral'->>'source_type' ORDER BY occurred_at DESC))[1],
                   (array_agg(provider_fields->'referral'->>'headline' ORDER BY occurred_at DESC))[1],
                   (array_agg(provider_fields->'referral'->>'source_url' ORDER BY occurred_at DESC))[1],
                   min(occurred_at), max(occurred_at)
            FROM growth_evidence
            WHERE evidence_type = 'meta_ctwa' AND provider_fields->'referral'->>'source_id' IS NOT NULL
            GROUP BY tenant_id, provider_fields->'referral'->>'source_id'
            ON CONFLICT DO NOTHING
        SQL);
        });
    }

    public function down(): void
    {
        Schema::table('growth_evidence', fn (Blueprint $table) => $table->dropColumn(['conversation_id', 'phone_number_id']));
        Schema::dropIfExists('growth_meta_ads');
    }
};
