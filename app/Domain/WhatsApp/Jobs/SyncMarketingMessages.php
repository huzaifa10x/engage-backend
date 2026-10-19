<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Jobs;

use App\Application\WhatsApp\MarketingMessages;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Enums\WabaStatus;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Infrastructure\Meta\MetaApiException;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Brings one account's Marketing Messages status up to date and, with $enroll, asks the business to
 * accept the terms when it is eligible and nobody asked recently. Runs after onboarding, hourly for
 * every connected account (the safety net behind the webhook), and from the backfill command.
 * Never throws for a Meta refusal: the status simply stays as it is.
 */
final class SyncMarketingMessages implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $wabaAccountId, public readonly bool $enroll = true, public readonly string $source = 'backfill_batch')
    {
        $this->onQueue(QueueName::Sync->value);
    }

    public function uniqueId(): string
    {
        return $this->wabaAccountId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(TenantContext $context, MarketingMessages $mm): void
    {
        if (! MarketingMessages::enabled()) {
            return;
        }
        $waba = $context->bypass(fn () => WabaAccount::query()->find($this->wabaAccountId));
        $tenant = $waba !== null ? $context->bypass(fn () => Tenant::query()->find($waba->tenant_id)) : null;
        if ($waba === null || $tenant === null || $waba->status !== WabaStatus::Connected) {
            return;
        }

        $context->run($tenant, function () use ($mm, $waba) {
            try {
                $mm->sync($waba, $this->source === 'embedded_signup' ? 'embedded_signup' : 'sync');
                if ($this->enroll && $mm->shouldAsk($waba)) {
                    $mm->enroll($waba, $this->source);
                }
            } catch (MetaApiException $e) {
                if ($e->isTransient() && $this->attempts() < $this->tries) {
                    throw $e;
                }
                Log::info('Marketing Messages sync skipped.', ['waba_id' => $waba->waba_id] + $e->context());
            } catch (Throwable $e) {
                // An expired connection or similar: nothing to do until the account is reconnected.
                Log::info('Marketing Messages sync skipped.', ['waba_id' => $waba->waba_id, 'error' => $e->getMessage()]);
            }
        });
    }
}
