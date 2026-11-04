<?php

declare(strict_types=1);

namespace App\Domain\Growth\Jobs;

use App\Domain\Growth\Website\WebsiteMatcher;
use App\Domain\Tenancy\TenantContext;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A website click was accepted: match any WhatsApp message that arrived before it with its code
 * (out-of-order delivery). Queued from the collector, so the collector never touches the CRM.
 */
final class ReconcileWebsiteClick implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $clickId)
    {
        $this->onQueue(QueueName::Default->value);
    }

    public function handle(TenantContext $context, WebsiteMatcher $matcher): void
    {
        $tenant = $context->tenantOrNull();
        if ($tenant !== null && $matcher->reconcile($this->clickId, $tenant) > 0) {
            RefreshMarketingAnalytics::dispatch($tenant->id, 2);
        }
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('Growth Hub could not reconcile a website click.', ['click_id' => $this->clickId, 'error' => $e?->getMessage()]);
    }
}
