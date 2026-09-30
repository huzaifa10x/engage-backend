<?php

declare(strict_types=1);

use App\Infrastructure\Database\MonthlyPartitionManager;
use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Append-only, monthly RANGE partitions on created_at. Retention = drop whole partitions
     * (after archive), per plan: 30 / 90 / 180 / 365 days / custom. The DEFAULT partition is a
     * safety net and should stay empty (`engage:partitions` warns otherwise).
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE audit_log (
                id          uuid        NOT NULL,
                tenant_id   uuid        NULL REFERENCES tenants (id) ON DELETE RESTRICT,
                actor_type  varchar(16) NOT NULL CHECK (actor_type IN ('user','admin','system','api')),
                actor_id    uuid        NULL,
                action      varchar(100) NOT NULL,
                entity_type varchar(64) NULL,
                entity_id   varchar(64) NULL,
                before      jsonb       NULL,
                after       jsonb       NULL,
                meta        jsonb       NULL,
                ip          inet        NULL,
                user_agent  varchar(512) NULL,
                request_id  varchar(64) NULL,
                created_at  timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (id, created_at)
            ) PARTITION BY RANGE (created_at);

            CREATE INDEX audit_log_tenant_created_idx ON audit_log (tenant_id, created_at DESC);
            CREATE INDEX audit_log_tenant_entity_idx ON audit_log (tenant_id, entity_type, entity_id);
            CREATE INDEX audit_log_tenant_action_idx ON audit_log (tenant_id, action, created_at DESC);

            CREATE TABLE audit_log_default PARTITION OF audit_log DEFAULT;
        SQL);

        app(MonthlyPartitionManager::class)->ensure('audit_log', (int) config('engage.partitions.months_ahead', 3));

        TenantSchema::enableRls('audit_log');
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS audit_log CASCADE');
    }
};
