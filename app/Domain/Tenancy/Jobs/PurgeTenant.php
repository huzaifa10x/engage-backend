<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Jobs;

use App\Application\WhatsApp\ManageChannels;
use App\Domain\Audit\AuditLogger;
use App\Domain\Messaging\Models\Media;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Enums\WabaStatus;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Erases a deleted company: disconnects its WhatsApp accounts at Meta (best effort), deletes its
 * media files, then every row that carries its tenant_id, children before parents (worked out from
 * the database's own foreign keys, so new tables are covered without changing this job), and
 * finally the company itself. Safe to run again: it only ever deletes this tenant's rows.
 */
final class PurgeTenant implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 1800;

    public function __construct(public readonly string $tenantId, public readonly string $reason = '')
    {
        $this->onQueue(QueueName::Maintenance->value);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 600];
    }

    public function handle(TenantContext $context, ManageChannels $channels, AuditLogger $audit): void
    {
        /** @var ?Tenant $tenant */
        $tenant = $context->bypass(fn () => Tenant::query()->withTrashed()->find($this->tenantId));
        if ($tenant === null) {
            return;
        }
        $name = (string) $tenant->name;

        $context->run($tenant, function () use ($channels): void {
            foreach (WabaAccount::query()->where('status', WabaStatus::Connected)->get() as $waba) {
                try {
                    $channels->disconnect($waba, 'company_deleted');
                } catch (Throwable $e) {
                    Log::warning('Could not disconnect a WhatsApp account while deleting a company.', ['waba_id' => $waba->waba_id, 'error' => $e->getMessage()]);
                }
            }
            Media::query()->whereNotNull('path')->select(['id', 'disk', 'path'])->lazyById(500)->each(function (Media $media): void {
                try {
                    Storage::disk($media->disk ?: (string) config('filesystems.default'))->delete((string) $media->path);
                } catch (Throwable) {
                    // a missing file is fine
                }
            });
        });

        $rows = $context->bypass(fn () => DB::transaction(function () use ($tenant): array {
            $counts = [];
            foreach (self::tablesInDeleteOrder() as $table) {
                $deleted = DB::table($table)->where('tenant_id', $tenant->id)->delete();
                if ($deleted > 0) {
                    $counts[$table] = $deleted;
                }
            }
            DB::table('users')->where('last_active_tenant_id', $tenant->id)->update(['last_active_tenant_id' => null]);
            DB::table('tenants')->where('id', $tenant->id)->delete();

            return $counts;
        }));

        $context->bypass(fn () => $audit->record('tenant.deleted', null, meta: [
            'tenant_id' => $this->tenantId, 'name' => $name, 'reason' => $this->reason, 'rows' => array_sum($rows), 'tables' => $rows,
        ]));
    }

    /**
     * Every table with a tenant_id column (partitioned tables by their parent), ordered so that a
     * table comes before the tables it references.
     *
     * @return list<string>
     */
    public static function tablesInDeleteOrder(): array
    {
        $tables = array_map(fn ($r) => (string) $r->name, DB::select(<<<'SQL'
            SELECT c.relname AS name FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            JOIN pg_attribute a ON a.attrelid = c.oid AND a.attname = 'tenant_id' AND NOT a.attisdropped
            WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'p') AND NOT c.relispartition
            ORDER BY c.relname
        SQL));
        $edges = DB::select(<<<'SQL'
            SELECT DISTINCT child.relname AS child, parent.relname AS parent FROM pg_constraint con
            JOIN pg_class child ON child.oid = con.conrelid
            JOIN pg_class parent ON parent.oid = con.confrelid
            WHERE con.contype = 'f' AND con.conrelid <> con.confrelid AND NOT child.relispartition
        SQL);

        $set = array_flip($tables);
        /** @var array<string, list<string>> $children parent => tables referencing it */
        $children = [];
        foreach ($edges as $edge) {
            if (isset($set[$edge->child], $set[$edge->parent])) {
                $children[(string) $edge->parent][] = (string) $edge->child;
            }
        }

        $ordered = [];
        $state = [];
        $visit = function (string $table) use (&$visit, &$ordered, &$state, $children): void {
            if (isset($state[$table])) {
                return; // done, or a cycle (deleted in one transaction, so it still works out)
            }
            $state[$table] = true;
            foreach ($children[$table] ?? [] as $child) {
                $visit($child);
            }
            $ordered[] = $table;
        };
        foreach ($tables as $table) {
            $visit($table);
        }

        return $ordered;
    }
}
