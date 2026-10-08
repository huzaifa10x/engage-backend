<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Jobs;

use App\Application\WhatsApp\AccessRevocation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Support\Queue\QueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Follows up an authorisation error from Meta: finds the account the refused call was about and
 * lets AccessRevocation decide whether our access is really gone. Queued, so the call that hit
 * the error (sending a message, syncing templates) is not slowed down by the check.
 */
final class VerifyMetaAccess implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $objectId, public readonly ?int $metaCode)
    {
        $this->onQueue(QueueName::Default->value);
    }

    public function handle(TenantContext $context, AccessRevocation $revocation): void
    {
        $waba = $context->bypass(function (): ?WabaAccount {
            $waba = WabaAccount::query()->where('waba_id', $this->objectId)->first();
            if ($waba !== null) {
                return $waba;
            }
            $number = PhoneNumber::query()->where('phone_number_id', $this->objectId)->first();

            return $number !== null ? WabaAccount::query()->find($number->waba_account_id) : null;
        });
        $tenant = $waba !== null ? $context->bypass(fn () => Tenant::query()->find($waba->tenant_id)) : null;
        if ($waba === null || $tenant === null) {
            return; // a call that was not about a connected account (sign-up in progress, app-level calls)
        }

        $context->run($tenant, fn () => $revocation->verify(WabaAccount::query()->findOrFail($waba->id), $this->metaCode));
    }
}
