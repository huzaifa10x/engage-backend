<?php

declare(strict_types=1);

use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coexistence import, paced and countable: records received from the WhatsApp Business app wait
 * in a queue table and are imported at a fixed rate per workspace, and each sync job counts how
 * many records it has received and imported so progress can be shown exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coexistence_sync_items', function (Blueprint $table) {
            $table->bigIncrements('id');                      // arrival order = import order
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('phone_number_id');
            $table->string('kind', 16);                       // contact | message
            $table->string('thread_user', 191)->nullable();   // whose chat a history message belongs to
            $table->jsonb('payload');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'id']);
            $table->index(['phone_number_id', 'kind']);
        });
        TenantSchema::enableRls('coexistence_sync_items');

        Schema::table('coexistence_sync_jobs', function (Blueprint $table) {
            $table->unsignedInteger('records_received')->default(0);
            $table->unsignedInteger('records_imported')->default(0);
            $table->timestampTz('last_imported_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('coexistence_sync_jobs', function (Blueprint $table) {
            $table->dropColumn(['records_received', 'records_imported', 'last_imported_at']);
        });
        Schema::dropIfExists('coexistence_sync_items');
    }
};
