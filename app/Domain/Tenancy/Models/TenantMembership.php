<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use App\Domain\Access\Models\Role;
use App\Domain\Access\SystemRole;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use App\Domain\Tenancy\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's seat in a tenant, carrying their role. Users are global identities; one user may hold
 * memberships in many tenants (agencies, consultants, 10X staff).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $role_id
 * @property MembershipStatus $status
 * @property ?Role $role
 * @property ?User $user
 * @property ?Tenant $tenant
 */
class TenantMembership extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'user_id', 'role_id', 'status', 'invited_by_user_id', 'joined_at'];

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'joined_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', MembershipStatus::Active);
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    public function isOwner(): bool
    {
        return $this->role?->key === SystemRole::Owner->value && $this->role->is_system;
    }
}
