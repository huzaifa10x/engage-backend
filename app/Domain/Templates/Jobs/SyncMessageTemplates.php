<?php

declare(strict_types=1);

namespace App\Domain\Templates\Jobs;

use App\Application\Templates\TemplateSync;
use App\Domain\WhatsApp\Exceptions\WhatsappException;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Infrastructure\Meta\MetaApiException;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes one WABA's templates from Meta. Runs in the tenant's context (restored from Laravel
 * Context by JobTenantContext). Unique per WABA so webhook bursts collapse into one sync.
 */
final class SyncMessageTemplates implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $uniqueFor = 60;

    public function __construct(public readonly string $wabaAccountId)
    {
        $this->onQueue(QueueName::Default->value);
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

    public function handle(TemplateSync $sync): void
    {
        $waba = WabaAccount::query()->find($this->wabaAccountId);
        if ($waba === null) {
            return;
        }

        try {
            $sync->syncWaba($waba);
        } catch (MetaApiException $e) {
            if ($e->isTransient() && $this->attempts() < $this->tries) {
                throw $e;
            }
            // Permanent (expired token, missing permission): keep what we have, surface in logs.
            Log::warning('Template sync failed', ['waba_id' => $waba->waba_id] + $e->context());
        } catch (WhatsappException $e) {
            Log::warning('Template sync skipped', ['waba_id' => $waba->waba_id, 'reason' => $e->getMessage()]);
        }
    }
}
