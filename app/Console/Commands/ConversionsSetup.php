<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Conversions\ConversionsAccess;
use App\Domain\Conversions\Models\MetaDataset;
use App\Domain\Conversions\Services\ConversionReporting;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Console\Command;

/**
 * Platform team only, until the Integrations card exists (batch 3b): switch a workspace on for
 * Conversions to Meta, and turn reporting on or off for one of its WhatsApp accounts.
 *
 *   php artisan engage:conversions --allow=<workspace ID>        let this workspace use conversions
 *   php artisan engage:conversions --disallow=<workspace ID>
 *   php artisan engage:conversions --enable=<WABA ID>            create/find the dataset and report
 *   php artisan engage:conversions --disable=<WABA ID>
 *   php artisan engage:conversions --status                      what is switched on, everywhere
 */
final class ConversionsSetup extends Command
{
    protected $signature = 'engage:conversions {--allow=} {--disallow=} {--enable=} {--disable=} {--status}';

    protected $description = 'Conversions to Meta: switch a workspace on, and turn reporting on or off per WhatsApp account.';

    public function handle(TenantContext $context, ConversionReporting $reporting): int
    {
        foreach (['allow' => true, 'disallow' => false] as $option => $value) {
            if (($id = $this->option($option)) !== null) {
                $tenant = $context->bypass(fn () => Tenant::query()->find($id));
                if ($tenant === null) {
                    $this->error("No workspace {$id}.");

                    return self::FAILURE;
                }
                $context->run($tenant, fn () => ConversionsAccess::set($tenant, $value));
                $this->info(($value ? 'Allowed' : 'No longer allowed').": {$tenant->name}.");
            }
        }

        foreach (['enable', 'disable'] as $option) {
            if (($wabaId = $this->option($option)) === null) {
                continue;
            }
            $waba = $context->bypass(fn () => WabaAccount::query()->where('waba_id', $wabaId)->where('status', 'connected')->first());
            $tenant = $waba !== null ? $context->bypass(fn () => Tenant::query()->find($waba->tenant_id)) : null;
            if ($waba === null || $tenant === null) {
                $this->error("No connected WhatsApp account {$wabaId}.");

                return self::FAILURE;
            }
            if ($option === 'disable') {
                $context->run($tenant, fn () => $reporting->disable($waba));
                $this->info("Reporting off for {$wabaId}.");

                continue;
            }
            $result = $context->run($tenant, fn () => $reporting->enable($tenant, $waba));
            $this->line(match ($result) {
                ConversionReporting::ENABLED => "Reporting on for {$wabaId}: dataset ".$context->bypass(fn () => $reporting->datasetFor($wabaId)?->dataset_id).'.',
                ConversionReporting::NOT_ENABLED => "Not enabled: the token of {$wabaId} does not carry whatsapp_business_manage_events. Reconnect the account to grant it.",
                default => "Not allowed: switch the workspace on first (--allow={$tenant->id}).",
            });
        }

        if ($this->option('status')) {
            $context->bypass(function () {
                foreach (Tenant::query()->get() as $tenant) {
                    if (! ConversionsAccess::allowed($tenant)) {
                        continue;
                    }
                    $this->line("Workspace {$tenant->name} ({$tenant->id}): allowed");
                    foreach (WabaAccount::query()->where('tenant_id', $tenant->id)->where('status', 'connected')->get() as $waba) {
                        $dataset = MetaDataset::query()->where('waba_id', $waba->waba_id)->first();
                        $this->line("   WABA {$waba->waba_id}: permission ".var_export($waba->events_permission, true).', reporting '.($dataset?->enabled ? "on (dataset {$dataset->dataset_id})" : 'off'));
                    }
                }
            });
        }

        return self::SUCCESS;
    }
}
