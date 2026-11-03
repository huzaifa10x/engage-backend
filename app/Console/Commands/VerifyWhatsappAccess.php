<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\WhatsApp\AccessRevocation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Enums\WabaStatus;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Console\Command;
use Throwable;

/**
 * Every two minutes: asks Meta, for every connected WhatsApp account, whether it and its numbers are still ours,
 * and brings back numbers that were marked offboarded once Meta answers normally for them again.
 * Catches the quiet cases: a customer removes 10X Engage in Meta Business Settings and no webhook
 * tells us, and the workspace happens not to send anything that would hit an error.
 */
final class VerifyWhatsappAccess extends Command
{
    protected $signature = 'engage:whatsapp:verify-access';

    protected $description = 'Check that Meta still accepts our access to every connected WhatsApp account; disconnect those it does not.';

    public function handle(TenantContext $context, AccessRevocation $revocation): int
    {
        $accounts = $context->bypass(fn () => WabaAccount::query()->where('status', WabaStatus::Connected->value)->get(['id', 'tenant_id']));
        $disconnected = 0;

        foreach ($accounts as $account) {
            $tenant = $context->bypass(fn () => Tenant::query()->find($account->tenant_id));
            if ($tenant === null) {
                continue;
            }
            try {
                // Passing no error code makes verify() ask Meta first, and act only on a clear refusal.
                $disconnected += (int) $context->run($tenant, fn () => $revocation->verify(WabaAccount::query()->findOrFail($account->id)));
            } catch (Throwable $e) {
                $this->components->warn("Account {$account->id}: {$e->getMessage()}");
            }
        }
        // Numbers our own check took away (offboarded) come back once Meta answers normally for them again.
        // Accounts whose access as a whole is gone (partner removed, refused, disconnected by hand) need a reconnect from Channels.
        $offboarded = $context->bypass(fn () => WabaAccount::query()
            ->where(fn ($q) => $q->whereNull('disconnect_reason')->orWhere('disconnect_reason', 'offboarded'))
            ->whereHas('phoneNumbers', fn ($q) => $q->where('status', 'disconnected')->where('disconnect_reason', 'offboarded'))
            ->get(['id', 'tenant_id']));
        $recovered = 0;
        foreach ($offboarded as $account) {
            $tenant = $context->bypass(fn () => Tenant::query()->find($account->tenant_id));
            if ($tenant === null) {
                continue;
            }
            try {
                $recovered += $context->run($tenant, fn () => $revocation->recover(WabaAccount::query()->findOrFail($account->id)));
            } catch (Throwable $e) {
                $this->components->warn("Account {$account->id}: {$e->getMessage()}");
            }
        }

        $this->components->info("Checked {$accounts->count()} connected account(s); {$disconnected} had lost access and were marked disconnected; {$recovered} number(s) reconnected.");

        return self::SUCCESS;
    }
}
