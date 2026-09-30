<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\Scopes\TenantScope;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Global identity. Tenant access is expressed ONLY through TenantMembership — there is
 * deliberately no users.tenant_id column.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property ?string $last_active_tenant_id
 * @property ?Carbon $disabled_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'locale', 'timezone', 'last_active_tenant_id', 'last_login_at', 'disabled_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'disabled_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /** @return Attribute<string, string> */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value)));
    }

    /**
     * Memberships across ALL tenants. The context scope is removed because the relation is
     * already constrained by user_id; RLS allows rows where user_id = app.user_id.
     *
     * @return HasMany<TenantMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class)->withoutGlobalScope(TenantScope::class);
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
