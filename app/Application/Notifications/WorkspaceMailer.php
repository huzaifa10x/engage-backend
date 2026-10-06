<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Domain\Access\SystemRole;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends workspace-level system emails to the people responsible for the workspace. A failing
 * mail server must never break the action that triggered the email (a webhook, a payment).
 */
final class WorkspaceMailer
{
    public function __construct(private readonly TenantContext $context) {}

    /** @return Collection<int, User> the workspace's owners (active accounts only) */
    public function owners(Tenant $tenant): Collection
    {
        return $this->context->bypass(fn () => TenantMembership::query()->where('tenant_id', $tenant->id)->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->where('key', SystemRole::Owner->value)->where('is_system', true))
            ->with('user')->get()->map(fn (TenantMembership $m) => $m->user)->filter()->values());
    }

    public function toOwners(Tenant $tenant, BaseNotification $notification): void
    {
        $this->send($this->owners($tenant)->pluck('email')->all(), $notification);
    }

    /** Billing emails also go to the workspace's billing address when one is set. */
    public function toBilling(Tenant $tenant, BaseNotification $notification): void
    {
        $this->send(array_merge($this->owners($tenant)->pluck('email')->all(), array_filter([$tenant->billing_email])), $notification);
    }

    /** @param array<int, mixed> $emails */
    private function send(array $emails, BaseNotification $notification): void
    {
        foreach (array_unique(array_map(fn ($e) => mb_strtolower((string) $e), array_filter($emails))) as $email) {
            try {
                Notification::route('mail', $email)->notify(clone $notification);
            } catch (Throwable $e) {
                Log::warning('System email could not be queued', ['notification' => $notification::class, 'error' => $e->getMessage()]);
            }
        }
    }
}
