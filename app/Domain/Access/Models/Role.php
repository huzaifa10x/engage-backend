<?php

declare(strict_types=1);

namespace App\Domain\Access\Models;

use App\Domain\Access\Permission;
use App\Domain\Access\Scopes\RoleVisibilityScope;
use App\Domain\Access\SystemRole;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property ?string $tenant_id NULL = system role shared by all tenants
 * @property string $key
 * @property string $name
 * @property list<string> $permissions
 * @property bool $is_system
 */
class Role extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'key', 'name', 'description', 'permissions', 'is_system'];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_system' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new RoleVisibilityScope);
    }

    public static function system(SystemRole $role): self
    {
        return static::query()->whereNull('tenant_id')->where('key', $role->value)->firstOrFail();
    }

    public function grants(Permission $permission): bool
    {
        $granted = $this->permissions;

        return in_array('*', $granted, true) || in_array($permission->value, $granted, true);
    }

    public function systemRole(): ?SystemRole
    {
        return $this->is_system ? SystemRole::tryFrom($this->key) : null;
    }

    public function isOwnerRole(): bool
    {
        return $this->systemRole() === SystemRole::Owner;
    }
}
