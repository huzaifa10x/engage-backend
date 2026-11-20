<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Access\SystemRole;
use App\Domain\Messaging\Services\MissedLeadsReport;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Notifications\MissedLeadsReportNotification;
use Illuminate\Console\Command;
use Throwable;

/** Monday morning: last week's missed-leads report, emailed to every Owner of a workspace that had enquiries. */
final class SendMissedLeadsReports extends Command
{
    protected $signature = 'engage:reports:missed-leads';

    protected $description = 'Email last week’s missed-leads report to each workspace’s Owners.';

    public function handle(TenantContext $context, MissedLeadsReport $report): int
    {
        $sent = 0;
        $tenants = $context->bypass(fn () => Tenant::query()->where('status', TenantStatus::Active->value)
            ->whereIn('id', fn ($q) => $q->select('tenant_id')->from('enquiries')->where('started_at', '>=', now()->subDays(15)))->get());
        foreach ($tenants as $tenant) {
            try {
                $sent += $context->run($tenant, function () use ($tenant, $report): int {
                    $data = $report->build($tenant, 1);
                    if ($data['enquiries'] === 0) {
                        return 0;
                    }
                    $owners = TenantMembership::query()->active()->with(['role', 'user'])->get()
                        ->filter(fn (TenantMembership $m) => $m->role?->systemRole() === SystemRole::Owner && $m->user !== null);
                    foreach ($owners as $owner) {
                        $owner->user->notify(new MissedLeadsReportNotification($tenant->name, $data));
                    }

                    return $owners->count();
                });
            } catch (Throwable $e) {
                report($e);
            }
        }
        $this->components->info("Missed-leads report: {$sent} email(s) sent.");

        return self::SUCCESS;
    }
}
