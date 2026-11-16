<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Conversions\ConversionsAccess;
use App\Domain\Conversions\Models\ConversionRule;
use App\Domain\Conversions\Models\MetaDataset;
use App\Domain\Conversions\Services\ConversionReporting;
use App\Domain\Conversions\Services\ConversionRules;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\WabaAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Platform team only, until the Integrations card exists (batch 3b): switch a workspace on for
 * Conversions to Meta, and turn reporting on or off for one of its WhatsApp accounts.
 *
 *   php artisan engage:conversions --allow=<workspace ID or WABA ID>   let this workspace use conversions
 *   php artisan engage:conversions --disallow=<workspace ID>
 *   php artisan engage:conversions --enable=<WABA ID>            create/find the dataset and report
 *   php artisan engage:conversions --disable=<WABA ID>
 *   php artisan engage:conversions --status                      what is switched on, everywhere
 *   php artisan engage:conversions --workspace=<ID> --lead-tag=booked --lead-step=<flow>:<step> --purchases=on
 */
final class ConversionsSetup extends Command
{
    protected $signature = 'engage:conversions {--allow=} {--disallow=} {--enable=} {--disable=} {--status}
        {--workspace= : For the rule options below}
        {--lead-tag= : This tag added to a contact counts as a lead}
        {--lead-step= : Reaching this flow step counts as a lead ("<flow id>:<step id>")}
        {--purchases= : on | off: paid WooCommerce orders linked to a chat count as purchases}';

    protected $description = 'Conversions to Meta: switch a workspace on, and turn reporting on or off per WhatsApp account.';

    public function handle(TenantContext $context, ConversionReporting $reporting, ConversionRules $rules): int
    {
        foreach (['allow' => true, 'disallow' => false] as $option => $value) {
            if (($id = $this->option($option)) !== null) {
                // A workspace ID, or the ID of a WhatsApp account connected to it.
                $tenant = $context->bypass(fn () => Tenant::query()->find(Str::isUuid($id) ? $id : WabaAccount::query()->where('waba_id', $id)->value('tenant_id')));
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

        if (($workspace = $this->option('workspace')) !== null) {
            $tenant = $context->bypass(fn () => Tenant::query()->find($workspace));
            if ($tenant === null) {
                $this->error("No workspace {$workspace}.");

                return self::FAILURE;
            }
            $context->run($tenant, function () use ($rules) {
                if (is_string($tag = $this->option('lead-tag')) && $tag !== '') {
                    $rules->leadOnTag($tag);
                    $this->info("Lead: tag \"{$tag}\".");
                }
                if (is_string($step = $this->option('lead-step')) && str_contains($step, ':')) {
                    [$flow, $node] = explode(':', $step, 2);
                    $rules->leadOnFlowStep($flow, $node);
                    $this->info("Lead: flow step {$step}.");
                }
                if (in_array($this->option('purchases'), ['on', 'off'], true)) {
                    $rules->purchasesFromOrders($this->option('purchases') === 'on');
                    $this->info('Purchases from linked WooCommerce orders: '.$this->option('purchases').'.');
                }
            });
        }

        if ($this->option('status')) {
            $context->bypass(function () {
                foreach (Tenant::query()->get() as $tenant) {
                    if (! ConversionsAccess::allowed($tenant)) {
                        continue;
                    }
                    $this->line("Workspace {$tenant->name} ({$tenant->id}): allowed");
                    foreach (ConversionRule::query()->where('tenant_id', $tenant->id)->get() as $rule) {
                        $this->line("   rule {$rule->event_name} on {$rule->trigger_type}".($rule->trigger_ref !== null ? " {$rule->trigger_ref}" : '').($rule->enabled ? '' : ' (off)'));
                    }
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
