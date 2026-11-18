<?php

declare(strict_types=1);

namespace App\Application\Tenancy;

use App\Application\Admin\DeleteAccounts;
use App\Domain\Audit\AuditLogger;
use App\Domain\Developer\Models\ApiKey;
use App\Domain\Developer\Models\WebhookEndpoint;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Notifications\WorkspaceDeletionNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The Owner deletes their workspace (batch 6, item 14). Typing its name confirms; it is deleted
 * after WAITING_DAYS unless cancelled. At once: API keys are revoked and webhooks paused, the
 * team (config engage.sales_email) is emailed, and it is in the Audit log. When the day comes the
 * workspace is closed and erased exactly as a Super Admin deletion (DeleteAccounts), which stays
 * available to Super Admins at any time.
 */
final class WorkspaceDeletion
{
    public const WAITING_DAYS = 30;

    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    /** @return array{requested_at: ?string, scheduled_for: ?string, requested_by: ?string, waiting_days: int} */
    public function status(Tenant $tenant): array
    {
        $by = $tenant->getAttribute('deletion_requested_by');

        return [
            'requested_at' => $tenant->getAttribute('deletion_requested_at')?->toIso8601String(),
            'scheduled_for' => $tenant->getAttribute('deletion_scheduled_for')?->toIso8601String(),
            'requested_by' => is_string($by) ? DB::table('users')->where('id', $by)->value('name') : null,
            'waiting_days' => self::WAITING_DAYS,
        ];
    }

    public function request(Tenant $tenant, string $confirmName): void
    {
        if (trim($confirmName) !== trim((string) $tenant->name)) {
            throw ValidationException::withMessages(['confirm' => 'Type the workspace name exactly as shown to confirm.']);
        }
        if ($tenant->getAttribute('deletion_scheduled_for') !== null) {
            throw ValidationException::withMessages(['confirm' => 'Deletion is already scheduled.']);
        }

        $at = now();
        $paused = DB::transaction(function () use ($tenant, $at): array {
            $tenant->forceFill(['deletion_requested_at' => $at, 'deletion_scheduled_for' => $at->copy()->addDays(self::WAITING_DAYS), 'deletion_requested_by' => $this->context->userId()])->save();
            ApiKey::query()->whereNull('revoked_at')->update(['revoked_at' => $at]);
            $paused = WebhookEndpoint::query()->where('status', 'active')->pluck('id')->all();
            WebhookEndpoint::query()->whereIn('id', $paused)->update(['status' => 'paused']);
            $settings = (array) ($tenant->settings ?? []);
            $settings['deletion_paused_webhooks'] = $paused;
            $tenant->forceFill(['settings' => $settings])->save();

            return $paused;
        });

        $this->audit->record('tenant.deletion_requested', $tenant, after: ['scheduled_for' => $tenant->getAttribute('deletion_scheduled_for')?->toIso8601String()],
            meta: ['webhooks_paused' => count($paused)]);
        $this->tellTeam($tenant, 'requested');
    }

    /** Cancelled during the waiting period: webhooks paused by the request switch back on. API keys stay revoked (make new ones). */
    public function cancel(Tenant $tenant): void
    {
        if ($tenant->getAttribute('deletion_scheduled_for') === null) {
            throw ValidationException::withMessages(['deletion' => 'No deletion is scheduled.']);
        }
        DB::transaction(function () use ($tenant): void {
            $settings = (array) ($tenant->settings ?? []);
            WebhookEndpoint::query()->whereIn('id', (array) ($settings['deletion_paused_webhooks'] ?? []))->where('status', 'paused')->update(['status' => 'active']);
            unset($settings['deletion_paused_webhooks']);
            $tenant->forceFill(['deletion_requested_at' => null, 'deletion_scheduled_for' => null, 'deletion_requested_by' => null, 'settings' => $settings])->save();
        });
        $this->audit->record('tenant.deletion_cancelled', $tenant);
        $this->tellTeam($tenant, 'cancelled');
    }

    /** Workspaces whose waiting period is over are closed and erased (the scheduler runs this hourly). */
    public function runDue(DeleteAccounts $delete): int
    {
        $due = $this->context->bypass(fn () => Tenant::query()->where('status', TenantStatus::Active)
            ->whereNotNull('deletion_scheduled_for')->where('deletion_scheduled_for', '<=', now())->get());
        foreach ($due as $tenant) {
            $delete->deleteScheduled($tenant, 'Deleted by the Owner after the '.self::WAITING_DAYS.'-day waiting period');
            $this->tellTeam($tenant, 'deleted');
        }

        return $due->count();
    }

    private function tellTeam(Tenant $tenant, string $what): void
    {
        $to = (string) config('engage.sales_email');
        if ($to !== '') {
            Notification::route('mail', $to)->notify(new WorkspaceDeletionNotification($tenant->id, (string) $tenant->name, $what,
                $tenant->getAttribute('deletion_scheduled_for')?->toIso8601String(), $this->context->userId() !== null ? DB::table('users')->where('id', $this->context->userId())->value('email') : null));
        }
    }
}
