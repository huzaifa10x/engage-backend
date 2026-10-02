<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\Usage\MessageTemplatesCounter;
use App\Domain\Plans\Usage\TeamSeatsCounter;
use App\Domain\Plans\Usage\UsageCounterRegistry;
use App\Domain\Plans\Usage\WhatsappNumbersCounter;
use App\Domain\Tenancy\Database\PostgresSessionVariables;
use App\Domain\Tenancy\Queue\JobTenantContext;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires tenant context across every entry point:
 *   HTTP   — ResolveTenant middleware (set) / terminate (clear)
 *   Queue  — tenant_id travels in Laravel Context with every dispatched job; restored before
 *            handle() and the caller's context put back after (see JobTenantContext)
 *   DB     — session variables re-applied on every (re)connection
 */
final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PostgresSessionVariables::class);
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(JobTenantContext::class);

        $this->app->singleton(UsageCounterRegistry::class, fn ($app) => new UsageCounterRegistry([
            $app->make(TeamSeatsCounter::class),
            $app->make(WhatsappNumbersCounter::class),
            $app->make(MessageTemplatesCounter::class),
        ]));

        $this->app->singleton(EntitlementService::class);
    }

    public function boot(): void
    {
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            $this->app->make(PostgresSessionVariables::class)->reapply($event->connection);
        });

        Context::hydrated(fn (ContextRepository $context) => $this->app->make(JobTenantContext::class)->hydrated($context));

        Event::listen(JobProcessing::class, fn (JobProcessing $event) => $this->app->make(JobTenantContext::class)->started($event->job));

        Event::listen(
            [JobProcessed::class, JobExceptionOccurred::class, JobFailed::class],
            fn (JobProcessed|JobExceptionOccurred|JobFailed $event) => $this->app->make(JobTenantContext::class)->finished($event->job),
        );
    }
}
