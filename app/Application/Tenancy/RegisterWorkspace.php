<?php

declare(strict_types=1);

namespace App\Application\Tenancy;

use App\Domain\Access\Models\Role;
use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditLogger;
use App\Domain\Billing\SubscriptionService;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Enums\MembershipStatus;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Self-serve signup: user + workspace + owner membership + Pro trial, atomically.
 */
final class RegisterWorkspace
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SubscriptionService $subscriptions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string, company_name: string, timezone?: ?string, country?: ?string}  $input
     * @return array{user: User, tenant: Tenant, membership: TenantMembership}
     */
    public function __invoke(array $input): array
    {
        return DB::transaction(function () use ($input) {
            [$user, $tenant, $membership] = $this->context->bypass(function () use ($input) {
                $user = User::query()->create([
                    'name' => $input['name'],
                    'email' => $input['email'],
                    'password' => $input['password'],
                ]);

                $tenant = Tenant::query()->create([
                    'name' => $input['company_name'],
                    'slug' => $this->uniqueSlug($input['company_name']),
                    'status' => TenantStatus::Active,
                    'timezone' => $input['timezone'] ?? config('engage.tenancy.default_timezone'),
                    'locale' => 'en',
                    'currency' => config('engage.tenancy.default_currency'),
                    'country' => $input['country'] ?? null,
                    'billing_email' => $input['email'],
                    'created_by_user_id' => $user->getKey(),
                ]);

                $membership = TenantMembership::query()->create([
                    'tenant_id' => $tenant->getKey(),
                    'user_id' => $user->getKey(),
                    'role_id' => Role::system(SystemRole::Owner)->getKey(),
                    'status' => MembershipStatus::Active,
                    'joined_at' => now(),
                ]);

                $user->forceFill(['last_active_tenant_id' => $tenant->getKey()])->save();

                return [$user, $tenant, $membership];
            });

            $this->context->run($tenant, function (Tenant $tenant) use ($membership) {
                $this->audit->record('tenant.created', $tenant, after: $tenant->only(['name', 'slug', 'timezone', 'currency']));
                $this->audit->record('membership.created', $membership, after: ['role' => SystemRole::Owner->value]);
                $this->subscriptions->startTrial($tenant); // inside the owner's context → audited as the owner
            }, $membership);

            return ['user' => $user, 'tenant' => $tenant, 'membership' => $membership];
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'workspace', 40, '');

        return $base.'-'.Str::lower(Str::random(6));
    }
}
