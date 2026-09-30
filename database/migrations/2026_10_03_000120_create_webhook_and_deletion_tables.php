<?php

declare(strict_types=1);

use App\Infrastructure\Database\MonthlyPartitionManager;
use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Every verified inbound Meta webhook change, stored BEFORE processing (replay source).
         * One row per entry.change. Platform table: tenants never read raw payloads.
         * Monthly RANGE(received_at); retention drops whole partitions (default 90 days).
         */
        DB::unprepared(<<<'SQL'
            CREATE TABLE webhook_inbound_log (
                id              uuid         NOT NULL,
                tenant_id       uuid         NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                object          varchar(64)  NOT NULL,
                waba_id         varchar(32)  NULL,
                phone_number_id varchar(32)  NULL,
                field           varchar(64)  NOT NULL,
                delivery_hash   char(64)     NOT NULL,
                payload         jsonb        NOT NULL,
                process_status  varchar(16)  NOT NULL DEFAULT 'pending'
                    CHECK (process_status IN ('pending','processed','ignored','deferred','failed')),
                attempts        smallint     NOT NULL DEFAULT 0,
                last_error      text         NULL,
                received_at     timestamptz  NOT NULL DEFAULT now(),
                processed_at    timestamptz  NULL,
                PRIMARY KEY (id, received_at)
            ) PARTITION BY RANGE (received_at);

            CREATE INDEX webhook_inbound_log_status_idx ON webhook_inbound_log (process_status, received_at);
            CREATE INDEX webhook_inbound_log_field_idx ON webhook_inbound_log (field, received_at DESC);
            CREATE INDEX webhook_inbound_log_waba_idx ON webhook_inbound_log (waba_id, received_at DESC);
            CREATE INDEX webhook_inbound_log_tenant_idx ON webhook_inbound_log (tenant_id, received_at DESC);

            CREATE TABLE webhook_inbound_log_default PARTITION OF webhook_inbound_log DEFAULT;
        SQL);

        app(MonthlyPartitionManager::class)->ensure('webhook_inbound_log', (int) config('engage.partitions.months_ahead', 3));
        TenantSchema::enableRls('webhook_inbound_log', using: 'engage_rls_bypass()', check: 'engage_rls_bypass()');

        // Delivery-level dedup: Meta retries an identical body for up to 7 days.
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->char('delivery_hash', 64)->primary();
            $table->unsignedSmallInteger('changes');
            $table->timestampTz('received_at')->useCurrent()->index();
        });

        /*
         * Meta data-deletion callback requests (Facebook Login users who removed the app).
         * Platform table, no tenant_id: the subject is a Facebook user, not a workspace.
         */
        Schema::create('data_deletion_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('confirmation_code', 32)->unique();
            $table->string('source', 16)->default('meta');               // meta | deauthorize
            $table->string('meta_user_id', 32)->index();
            $table->string('status', 16)->default('received');
            $table->jsonb('result')->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });
        TenantSchema::check('data_deletion_requests', 'data_deletion_requests_status_check',
            "status IN ('received','processing','completed','failed')");
    }

    public function down(): void
    {
        Schema::dropIfExists('data_deletion_requests');
        Schema::dropIfExists('webhook_deliveries');
        DB::unprepared('DROP TABLE IF EXISTS webhook_inbound_log CASCADE');
    }
};
