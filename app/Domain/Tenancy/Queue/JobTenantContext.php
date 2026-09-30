<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Queue;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Log\Context\Repository as ContextRepository;

/**
 * Restores tenant context for queued jobs and puts the caller's context back afterwards.
 *
 * Laravel hydrates Context for EVERY job — including `sync` jobs executed inside an HTTP request.
 * A stack (rather than "clear after job") guarantees a sync job cannot wipe or leak the
 * surrounding request's tenant, while a long-lived worker always returns to an empty context.
 *
 * Event order per job: Context hydrated → JobProcessing → handle() → JobProcessed | JobExceptionOccurred [→ JobFailed]
 */
final class JobTenantContext
{
    /** @var list<array{job: ?string, state: array{tenant: ?Tenant, membership: mixed, user: ?string, bypass: int}}> */
    private array $stack = [];

    public function __construct(private readonly TenantContext $context) {}

    public function hydrated(ContextRepository $data): void
    {
        // Read the job's context FIRST: restore() below syncs TenantContext back into Laravel
        // Context (forget('tenant_id')) — the same repository we are hydrating from.
        $tenantId = $data->get('tenant_id');
        $userId = $data->get('user_id');

        $this->stack[] = ['job' => null, 'state' => $this->context->snapshot()];

        $this->context->restore(['tenant' => null, 'membership' => null, 'user' => null, 'bypass' => 0]);

        if (! is_string($tenantId) || $tenantId === '') {
            return;
        }

        $tenant = $this->context->bypass(fn () => Tenant::query()->find($tenantId));
        if ($tenant === null) {
            return; // tenant deleted since dispatch — job runs without context and fails closed
        }

        $this->context->set($tenant);

        if (is_string($userId) && $userId !== '') {
            $this->context->setUser($userId);
        }
    }

    public function started(Job $job): void
    {
        $top = array_key_last($this->stack);

        if ($top !== null && $this->stack[$top]['job'] === null) {
            $this->stack[$top]['job'] = $this->jobKey($job);
        }
    }

    /** Called on JobProcessed / JobExceptionOccurred / JobFailed — restores at most once per job. */
    public function finished(Job $job): void
    {
        $top = array_key_last($this->stack);

        if ($top === null || $this->stack[$top]['job'] !== $this->jobKey($job)) {
            return;
        }

        $entry = array_pop($this->stack);
        $this->context->restore($entry['state']);
    }

    private function jobKey(Job $job): string
    {
        return (string) ($job->uuid() ?? $job->getJobId()).'#'.spl_object_id($job);
    }
}
