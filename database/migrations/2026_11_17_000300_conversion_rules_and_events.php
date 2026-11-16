<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Conversions to Meta (batch 3a): which moments count as a conversion (rules) and every conversion
 * found (events), with what happened to it. One Purchase per order and one LeadSubmitted per chat
 * and rule, enforced by the database: re-tagging, editing an order or rerunning a flow never
 * creates a second event.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversion_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();   // the workspace
            $table->string('trigger_type', 16);                                             // flow_step | tag | order
            $table->string('trigger_ref', 160)->nullable();                                 // "<flow id>:<step id>" | tag (lower case) | null (any linked order)
            $table->string('event_name', 40);                                               // LeadSubmitted | Purchase (ViewContent later)
            $table->boolean('enabled')->default(true);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'trigger_type', 'trigger_ref']);
        });
        TenantSchema::enableRls('conversion_rules');
        TenantSchema::check('conversion_rules', 'conversion_rules_trigger_check', "trigger_type IN ('flow_step','tag','order')");
        TenantSchema::check('conversion_rules', 'conversion_rules_event_check', "event_name IN ('LeadSubmitted','Purchase','ViewContent')");

        Schema::create('conversion_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();   // the workspace
            $table->uuid('conversation_id')->nullable();
            $table->uuid('contact_id')->nullable();
            $table->uuid('ad_click_id')->nullable();
            $table->uuid('rule_id')->nullable();
            $table->string('waba_id', 32)->nullable();
            $table->string('event_name', 40);
            $table->timestampTz('event_time');                                               // when the conversion happened
            $table->decimal('value', 14, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->uuid('order_id')->nullable();
            $table->string('status', 10)->default('queued');                                 // queued | sent | failed | expired | skipped
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->string('error_reason', 500)->nullable();                                 // why it was skipped / failed, in plain words
            $table->jsonb('meta_response')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->boolean('is_test')->default(false);
            $table->timestampsTz();

            $table->index(['status', 'next_attempt_at']);
            $table->index(['tenant_id', 'created_at']);
        });
        TenantSchema::enableRls('conversion_events');
        TenantSchema::check('conversion_events', 'conversion_events_status_check', "status IN ('queued','sent','failed','expired','skipped')");
        DB::statement("CREATE UNIQUE INDEX conversion_events_one_purchase_per_order ON conversion_events (tenant_id, order_id) WHERE event_name = 'Purchase' AND order_id IS NOT NULL AND is_test = false");
        DB::statement("CREATE UNIQUE INDEX conversion_events_one_lead_per_chat_rule ON conversion_events (tenant_id, conversation_id, rule_id) WHERE event_name = 'LeadSubmitted' AND is_test = false");
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_events');
        Schema::dropIfExists('conversion_rules');
    }
};
