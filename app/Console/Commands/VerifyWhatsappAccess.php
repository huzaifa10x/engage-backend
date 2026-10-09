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
 * Every two minutes: asks Meta, for every connected WhatsApp account, whether it and its numbers are still ours.
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
        $this->components->info("Checked {$accounts->count()} connected account(s); {$disconnected} had lost access and were marked disconnected.");

        return self::SUCCESS;
    }
}
