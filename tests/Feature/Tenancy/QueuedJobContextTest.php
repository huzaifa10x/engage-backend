<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Tests\TestCase;

final class RecordTenantJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public static ?string $seen = null;

    public function handle(TenantContext $context): void
    {
        self::$seen = $context->tenantOrNull()?->getKey();
    }
}

/** Regression: a job dispatched in tenant context must run in that tenant (sync and async). */
final class QueuedJobContextTest extends TestCase
{
    public function test_job_runs_in_the_dispatching_tenant_and_caller_context_is_restored(): void
    {
        $tenant = $this->createTenant();
        RecordTenantJob::$seen = null;

        $this->tenantContext()->run($tenant, function () use ($tenant) {
            RecordTenantJob::dispatch();
            $this->assertSame($tenant->getKey(), $this->tenantContext()->tenantOrNull()?->getKey(), 'caller context restored');
        });

        $this->assertSame($tenant->getKey(), RecordTenantJob::$seen);
    }
}
