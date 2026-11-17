<?php

declare(strict_types=1);

namespace App\Domain\Timeline\Jobs;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Timeline\Timeline;
use App\Domain\Timeline\TimelineHooks;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes captured timeline events (see TimelineHooks) in the background, after the change that caused
 * them — and the reply to the customer — has gone out. In the order they happened; one contact at a
 * time, so two messages arriving together can never start two enquiries.
 */
final class RecordTimeline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    /** @param list<array{op: string, payload: array<string, mixed>, seq: int, tenant_id: string, moment: array{membership: ?string, bulk: ?array{how: string, by: ?string}, at: string}}> $entries */
    public function __construct(public readonly string $tenantId, public readonly array $entries)
    {
        $this->onQueue(QueueName::Maintenance->value);
    }

    public function handle(TenantContext $context): void
    {
        $tenant = $context->bypass(fn () => Tenant::query()->find($this->tenantId));
        if ($tenant === null) {
            return;
        }
        $context->run($tenant, function (): void {
            foreach ($this->entries as $entry) {
                $key = (string) $entry['payload']['contact_id'];
                // One contact at a time across workers (enquiries depend on the order of messages).
                // Never retried as a whole (that would record twice): a busy lock or a failure is logged and skipped.
                $lock = Cache::lock('timeline:'.$this->tenantId.':'.$key, 30);
                $locked = false;
                try {
                    $locked = $lock->block(20);
                } catch (LockTimeoutException) {
                    Log::info('Timeline: contact busy, recording without waiting.', ['op' => $entry['op']]);
                }
                try {
                    DB::transaction(fn () => Timeline::replay($entry['moment'], fn () => TimelineHooks::apply($entry['op'], $entry['payload'])));
                } catch (Throwable $e) {
                    Log::warning('Timeline event was not recorded.', ['op' => $entry['op'], 'error' => $e->getMessage()]);
                } finally {
                    if ($locked) {
                        $lock->release();
                    }
                }
            }
        });
    }
}
