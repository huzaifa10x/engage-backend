<?php

declare(strict_types=1);

namespace App\Domain\Platform\Models;

use App\Domain\Identity\Models\PlatformAdmin;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Platform table (RLS: bypass only). Always read inside TenantContext::bypass().
 *
 * @property string $id
 * @property string $platform_admin_id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $reason
 * @property Carbon $token_expires_at
 * @property Carbon $expires_at
 * @property ?Carbon $consumed_at
 * @property ?Carbon $ended_at
 * @property ?PlatformAdmin $admin
 * @property ?User $user
 * @property ?Tenant $tenant
 */
class ImpersonationSession extends Model
{
    use HasUuids;

    protected $fillable = ['platform_admin_id', 'tenant_id', 'user_id', 'reason', 'token_hash', 'token_expires_at', 'expires_at', 'consumed_at', 'ended_at', 'ip'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PlatformAdmin, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'platform_admin_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isLive(): bool
    {
        return $this->consumed_at !== null && $this->ended_at === null && $this->expires_at->isFuture();
    }
}
