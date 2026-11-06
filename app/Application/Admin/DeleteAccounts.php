<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Application\Billing\StripeBilling;
use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Jobs\PurgeTenant;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Super Admin deletions. Permanent, so each one is confirmed by typing the name and given a reason.
 *
 *  Company: closed at once (nobody can use it), the paid Stripe subscription is cancelled, then
 *  PurgeTenant disconnects its WhatsApp accounts and erases all of its data. A record of the
 *  deletion (who, when, why) stays in the platform audit log.
 *
 *  User: their memberships, sessions, tokens and sign-in data are erased; messages and notes they
 *  wrote stay in the workspaces. Refused while they are the only Owner of a workspace that has
 *  other members (make someone else Owner, or delete the company).
 */
final class DeleteAccounts
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly StripeBilling $stripe,
    ) {}

    public function deleteCompany(Tenant $tenant, string $confirmName, string $reason): void
    {
        if (trim($confirmName) !== trim((string) $tenant->name)) {
            throw ValidationException::withMessages(['confirm' => 'Type the company name exactly as shown to confirm.']);
        }
        $review = $this->context->bypass(fn () => TenantMembership::query()->where('tenant_id', $tenant->id)
            ->whereHas('user', fn ($q) => $q->whereNotNull('review_account_at'))->exists());
        if ($review) {
            throw ValidationException::withMessages(['confirm' => 'This company holds the Meta review account. End the review account first (Users › the account › End review account).']);
        }

        // Stop billing before anything is erased; if Stripe cannot be reached, nothing changes.
        $live = $this->stripe->liveStripeSubscription($tenant);
        if ($live !== null) {
            try {
                $this->stripe->cancelNow($tenant);
            } catch (Throwable $e) {
                throw ValidationException::withMessages(['confirm' => 'The paid subscription could not be cancelled in Stripe, so nothing was deleted: '.$e->getMessage()]);
            }
        }

        $this->context->bypass(function () use ($tenant, $reason): void {
            $settings = (array) ($tenant->settings ?? []);
            $settings['deletion'] = ['started_at' => now()->toIso8601String(), 'reason' => $reason];
            $tenant->forceFill(['status' => TenantStatus::Closed, 'settings' => $settings])->save();
            $this->audit->record('tenant.deletion_started', null, meta: ['tenant_id' => $tenant->id, 'name' => $tenant->name, 'reason' => $reason]);
        });

        PurgeTenant::dispatch($tenant->id, $reason);
    }

    public function deleteUser(User $user, string $confirmEmail, string $reason): void
    {
        if (mb_strtolower(trim($confirmEmail)) !== mb_strtolower((string) $user->email)) {
            throw ValidationException::withMessages(['confirm' => 'Type the user’s email exactly as shown to confirm.']);
        }
        if ($user->isReviewAccount()) {
            throw ValidationException::withMessages(['confirm' => 'This is the Meta review account. End the review account first.']);
        }

        $blocking = $this->context->bypass(fn () => TenantMembership::query()->where('user_id', $user->id)->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->where('key', 'owner'))->with('tenant')->get()
            ->filter(function (TenantMembership $m) use ($user): bool {
                $others = TenantMembership::query()->where('tenant_id', $m->tenant_id)->where('user_id', '!=', $user->id);
                $otherOwner = (clone $others)->where('status', 'active')->whereHas('role', fn ($q) => $q->where('key', 'owner'))->exists();

                return ! $otherOwner && $others->exists();
            })
            ->map(fn (TenantMembership $m) => (string) $m->tenant?->name)->values()->all());
        if ($blocking !== []) {
            throw ValidationException::withMessages(['confirm' => 'They are the only Owner of '.implode(', ', $blocking).'. Make another member Owner first, or delete that company.']);
        }

        $snapshot = ['user_id' => $user->id, 'email' => $user->email, 'name' => $user->name, 'reason' => $reason];
        $this->context->bypass(function () use ($user, $snapshot): void {
            DB::transaction(function () use ($user, $snapshot): void {
                DB::table('impersonation_sessions')->where('user_id', $user->id)->delete();
                $user->tokens()->delete();
                DB::table('password_reset_tokens')->whereRaw('lower(email) = ?', [mb_strtolower((string) $user->email)])->delete();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $user->delete(); // memberships go with it (cascade)
                $this->audit->record('user.deleted', null, meta: $snapshot);
            });
        });
    }
}
