<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversions to Meta (batch 3a): one row per Click-to-WhatsApp ad click, from the referral on the
 * incoming message it started. ctwa_clid is kept exactly as Meta sent it: a conversion reported to
 * Meta later must carry the same click ID. A conversion uses the most recent click on its chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_clicks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();   // the workspace
            $table->uuid('conversation_id');
            $table->uuid('contact_id');
            $table->uuid('message_id')->nullable();                                         // the message that carried the referral
            $table->string('waba_id', 32);
            $table->text('ctwa_clid')->nullable();                                          // raw, untouched; null when Meta sent none
            $table->string('source_id', 64)->nullable();                                    // the ad ID
            $table->string('source_type', 20)->nullable();                                  // ad | post
            $table->text('source_url')->nullable();
            $table->text('headline')->nullable();
            $table->timestampTz('clicked_at');                                               // when the message from the ad arrived
            $table->timestampTz('created_at')->useCurrent();

            $table->unique('message_id');
            $table->index(['tenant_id', 'conversation_id', 'clicked_at']);
            $table->index(['tenant_id', 'contact_id', 'clicked_at']);
            $table->index(['tenant_id', 'source_id']);
        });
        TenantSchema::enableRls('ad_clicks');
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_clicks');
    }
};
