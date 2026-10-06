<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Team Inbox tools: canned responses, internal notes, snooze, and in-app notifications. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canned_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('shortcut', 40);                 // typed after "/" in the message box
            $table->text('body');
            $table->uuid('created_by_membership_id')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'shortcut']);
        });
        TenantSchema::enableRls('canned_responses');

        // Notes are for the team only: they are never sent to the customer.
        Schema::create('conversation_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->uuid('membership_id')->nullable();
            $table->text('body');
            $table->jsonb('mentions')->nullable();          // membership ids mentioned with @
            $table->timestampsTz();

            $table->index(['conversation_id', 'created_at']);
        });
        TenantSchema::enableRls('conversation_notes');

        Schema::create('member_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->uuid('membership_id');
            $table->string('type', 32);                     // assigned | mention | snooze_ended
            $table->string('title', 190);
            $table->string('body', 300)->nullable();
            $table->string('url', 190)->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampsTz();

            $table->index(['membership_id', 'read_at', 'created_at']);
        });
        TenantSchema::enableRls('member_notifications');

        Schema::table('conversations', function (Blueprint $table) {
            $table->timestampTz('snoozed_until')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('snoozed_until');
        });
        Schema::dropIfExists('member_notifications');
        Schema::dropIfExists('conversation_notes');
        Schema::dropIfExists('canned_responses');
    }
};
