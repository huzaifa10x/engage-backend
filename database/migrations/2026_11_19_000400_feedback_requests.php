<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CSAT and feedback requests (batch 5, item 4).
 *
 *  - kind csat: a one-tap rating (Good / OK / Poor) after a support chat is closed, only inside the
 *    24-hour window; the agent is the one who had the chat.
 *  - kind review: an approved utility template after a delivered store order or a completed booking.
 *  - never more than one request per contact in 7 days: requests not sent are kept with the reason.
 *  - (source, reference) is unique: an order or a chat close is never asked about twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('contact_id');
            $table->uuid('conversation_id')->nullable();
            $table->uuid('phone_number_id')->nullable();
            $table->string('kind', 10);                          // csat | review
            $table->string('source', 24);                        // conversation_closed | order_delivered | booking_completed
            $table->string('reference', 120);                    // what it is about: chat close, order, booking
            $table->uuid('agent_membership_id')->nullable();     // csat: who handled the chat
            $table->uuid('message_id')->nullable();
            $table->string('status', 10);                        // sent | answered | skipped | failed
            $table->string('reason', 190)->nullable();           // why it was not sent
            $table->string('rating', 4)->nullable();             // good | ok | poor
            $table->timestampTz('answered_at')->nullable();
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'source', 'reference']);
            $table->index(['tenant_id', 'contact_id', 'created_at']);
            $table->index(['tenant_id', 'kind', 'created_at']);
        });
        TenantSchema::enableRls('feedback_requests');
        TenantSchema::check('feedback_requests', 'feedback_requests_kind_check', "kind IN ('csat','review')");
        TenantSchema::check('feedback_requests', 'feedback_requests_status_check', "status IN ('sent','answered','skipped','failed')");
        TenantSchema::check('feedback_requests', 'feedback_requests_rating_check', "rating IS NULL OR rating IN ('good','ok','poor')");
        DB::statement("CREATE INDEX feedback_requests_recent ON feedback_requests (tenant_id, contact_id, created_at) WHERE status IN ('sent','answered')");
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_requests');
    }
};
