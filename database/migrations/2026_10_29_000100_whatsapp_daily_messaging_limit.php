<?php

declare(strict_types=1);

use App\Domain\Tenancy\TenantContext;
use Database\Support\TenantSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Meta's daily messaging limit, enforced by the platform:
 * - business_initiations: one row per person the business started a conversation with (a template
 *   sent while their 24-hour window was closed), per business portfolio, in the last 24 hours.
 *   Counting these rows is how SendMessage knows how much of the limit is left.
 * - campaigns.wait_reason: why a sending campaign is waiting (quiet hours, drip, daily limit).
 * - plans: "messages per second" shown as at most 35, the platform ceiling SendThroughput applies
 *   to every number whatever a plan or override says.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_initiations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('limit_key', 80);                // biz:<portfolio id> (or waba:<WABA id> when the portfolio is unknown)
            $table->string('recipient', 120);               // wa_id, or bsuid:<id>
            $table->uuid('phone_number_id');
            $table->uuid('message_id')->nullable();
            $table->timestampTz('created_at');

            $table->index(['limit_key', 'created_at']);
            $table->index(['limit_key', 'recipient', 'created_at']);
            $table->index('message_id');
        });
        TenantSchema::enableRls('business_initiations');

        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('wait_reason', 20)->nullable();    // quiet_hours | drip | daily_limit (with next_batch_at)
        });

        app(TenantContext::class)->bypass(function (): void {
            // What was already started in the last 24 hours counts from the first minute (templates
            // only; counting some sent inside an open window errs on the safe side).
            DB::statement(<<<'SQL'
                INSERT INTO business_initiations (id, tenant_id, limit_key, recipient, phone_number_id, message_id, created_at)
                SELECT gen_random_uuid(), x.tenant_id, x.limit_key, x.recipient, x.phone_number_id, x.message_id, x.created_at
                FROM (
                    SELECT DISTINCT ON (m.tenant_id, lk.limit_key, COALESCE(c.wa_id, 'bsuid:' || c.bsuid))
                        m.tenant_id, lk.limit_key, COALESCE(c.wa_id, 'bsuid:' || c.bsuid) AS recipient,
                        m.phone_number_id, m.id AS message_id, m.created_at
                    FROM messages m
                    JOIN contacts c ON c.id = m.contact_id
                    JOIN phone_numbers p ON p.id = m.phone_number_id
                    JOIN waba_accounts w ON w.id = p.waba_account_id
                    CROSS JOIN LATERAL (SELECT COALESCE('biz:' || w.meta_business_id, 'waba:' || w.waba_id) AS limit_key) lk
                    WHERE m.direction = 'outbound' AND m.type = 'template' AND m.status <> 'failed'
                      AND m.created_at >= now() - interval '24 hours'
                      AND (c.wa_id IS NOT NULL OR c.bsuid IS NOT NULL)
                    ORDER BY m.tenant_id, lk.limit_key, COALESCE(c.wa_id, 'bsuid:' || c.bsuid), m.created_at
                ) x
            SQL);

            DB::statement(<<<'SQL'
                UPDATE plan_version_features SET limit_value = 35
                WHERE feature_id IN (SELECT id FROM features WHERE key = 'messages_per_second')
                  AND (limit_value IS NULL OR limit_value > 35)
            SQL);
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('wait_reason');
        });
        Schema::dropIfExists('business_initiations');
    }
};
