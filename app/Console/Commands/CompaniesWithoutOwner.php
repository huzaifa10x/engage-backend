<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Admin\DeleteAccounts;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Companies left without an Owner: their owner's account was deleted before deleting a user also
 * deleted the companies they owned. Lists them (members, contacts, messages); with --delete, each is
 * deleted exactly as Super Admin › Companies › Delete does (Stripe cancelled, data erased).
 *
 *   php artisan engage:companies:without-owner
 *   php artisan engage:companies:without-owner --delete --reason="Owner account deleted earlier"
 */
final class CompaniesWithoutOwner extends Command
{
    protected $signature = 'engage:companies:without-owner
        {--delete : Delete the companies listed (asks to confirm)}
        {--reason= : Why (kept in the audit log; required with --delete)}';

    protected $description = 'List companies that have no active Owner left; optionally delete them.';

    public function handle(TenantContext $context, DeleteAccounts $delete): int
    {
        $tenants = $context->bypass(fn () => Tenant::query()->where('status', '!=', TenantStatus::Closed->value)
            ->whereNotExists(fn ($q) => $q->from('tenant_memberships as m')->join('roles as r', 'r.id', '=', 'm.role_id')
                ->whereColumn('m.tenant_id', 'tenants.id')->where('m.status', 'active')->where('r.key', 'owner'))
            ->orderBy('created_at')->get());

        if ($tenants->isEmpty()) {
            $this->components->info('Every company has an active Owner.');

            return self::SUCCESS;
        }

        $this->table(['Company', 'ID', 'Created', 'Members', 'Contacts', 'Messages'], $tenants->map(fn (Tenant $t) => $context->bypass(fn () => [
            $t->name, $t->id, $t->created_at?->toDateString(),
            DB::table('tenant_memberships')->where('tenant_id', $t->id)->count(),
            DB::table('contacts')->where('tenant_id', $t->id)->count(),
            DB::table('messages')->where('tenant_id', $t->id)->count(),
        ]))->all());

        if (! $this->option('delete')) {
            $this->components->warn('Preview only. Nothing was changed. Add --delete --reason="…" to delete these companies.');

            return self::SUCCESS;
        }
        $reason = trim((string) $this->option('reason'));
        if (mb_strlen($reason) < 5) {
            $this->components->error('Give a --reason of at least 5 characters (it is kept in the audit log).');

            return self::FAILURE;
        }
        if ($this->input->isInteractive() && ! $this->confirm("Permanently delete these {$tenants->count()} companies and all their data?", false)) {
            $this->components->info('Cancelled. Nothing was changed.');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($tenants as $tenant) {
            try {
                $delete->deleteCompany($tenant, (string) $tenant->name, $reason);
                $this->line("  Deleting \"{$tenant->name}\": closed now, data erased in the background.");
            } catch (ValidationException $e) {
                $failed++;
                $this->components->warn("\"{$tenant->name}\" was not deleted: ".collect($e->errors())->flatten()->first());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
