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
use Illuminate\Support\Collection;
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
 *  wrote stay in the workspaces. A company they own goes with them: every company where they are
 *  the only active Owner is deleted as above (its other members keep their own accounts and lose
 *  access to it). A company with another active Owner is kept; only this user's membership goes.
 *  A user who owns nothing is simply removed; no company or other user is affected.
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
        $this->ensureDeletable([$tenant]);
        $this->stopBilling([$tenant]);
        $this->closeAndPurge($tenant, $reason, null);
    }

    /** A workspace whose Owner asked for deletion and whose waiting period is over (WorkspaceDeletion). */
    public function deleteScheduled(Tenant $tenant, string $reason): void
    {
        $this->ensureDeletable([$tenant]);
        $this->stopBilling([$tenant]);
        $this->closeAndPurge($tenant, $reason, null);
    }

    /**
     * The companies that are deleted with this user: those where they are the only active Owner.
     *
     * @return Collection<int, Tenant>
     */
    public function ownedCompanies(User $user): Collection
    {
        return $this->context->bypass(fn () => TenantMembership::query()->where('user_id', $user->id)->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->where('key', 'owner'))->with('tenant')->get()
            ->filter(fn (TenantMembership $m) => $m->tenant !== null && ! TenantMembership::query()->where('tenant_id', $m->tenant_id)
                ->where('user_id', '!=', $user->id)->where('status', 'active')->whereHas('role', fn ($q) => $q->where('key', 'owner'))->exists())
            ->map(fn (TenantMembership $m) => $m->tenant)->unique('id')->values());
    }

    public function deleteUser(User $user, string $confirmEmail, string $reason): void
    {
        if (mb_strtolower(trim($confirmEmail)) !== mb_strtolower((string) $user->email)) {
            throw ValidationException::withMessages(['confirm' => 'Type the user’s email exactly as shown to confirm.']);
        }
        if ($user->isReviewAccount()) {
            throw ValidationException::withMessages(['confirm' => 'This is the Meta review account. End the review account first.']);
        }

        // Their companies go with them. Everything is checked (and billing stopped) before anything is erased.
        $companies = $this->ownedCompanies($user)->all();
        $this->ensureDeletable($companies);
        $this->stopBilling($companies);
        foreach ($companies as $company) {
            $this->closeAndPurge($company, $reason, $user);
        }

        $snapshot = ['user_id' => $user->id, 'email' => $user->email, 'name' => $user->name, 'reason' => $reason,
            'companies_deleted' => array_map(fn (Tenant $t) => ['id' => $t->id, 'name' => $t->name], $companies)];
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

    /** @param list<Tenant> $tenants */
    private function ensureDeletable(array $tenants): void
    {
        foreach ($tenants as $tenant) {
            $review = $this->context->bypass(fn () => TenantMembership::query()->where('tenant_id', $tenant->id)
                ->whereHas('user', fn ($q) => $q->whereNotNull('review_account_at'))->exists());
            if ($review) {
                throw ValidationException::withMessages(['confirm' => "{$tenant->name} holds the Meta review account. End the review account first (Users › the account › End review account)."]);
            }
        }
    }

    /**
     * Cancels each paid Stripe subscription first; if Stripe cannot be reached, nothing is deleted.
     *
     * @param  list<Tenant>  $tenants
     */
    private function stopBilling(array $tenants): void
    {
        foreach ($tenants as $tenant) {
            if ($this->stripe->liveStripeSubscription($tenant) === null) {
                continue;
            }
            try {
                $this->stripe->cancelNow($tenant);
            } catch (Throwable $e) {
                throw ValidationException::withMessages(['confirm' => "The paid subscription of {$tenant->name} could not be cancelled in Stripe, so nothing was deleted: ".$e->getMessage()]);
            }
        }
    }

    /** Closes the company at once (nobody can use it) and erases its data in the background. */
    private function closeAndPurge(Tenant $tenant, string $reason, ?User $withOwner): void
    {
        $this->context->bypass(function () use ($tenant, $reason, $withOwner): void {
            $settings = (array) ($tenant->settings ?? []);
            $settings['deletion'] = array_filter(['started_at' => now()->toIso8601String(), 'reason' => $reason, 'with_owner' => $withOwner?->email]);
            $tenant->forceFill(['status' => TenantStatus::Closed, 'settings' => $settings])->save();
            $this->audit->record('tenant.deletion_started', null, meta: array_filter(['tenant_id' => $tenant->id, 'name' => $tenant->name, 'reason' => $reason,
                'owner_deleted' => $withOwner !== null ? ['id' => $withOwner->id, 'email' => $withOwner->email] : null]));
        });

        PurgeTenant::dispatch($tenant->id, $reason);
    }
}
