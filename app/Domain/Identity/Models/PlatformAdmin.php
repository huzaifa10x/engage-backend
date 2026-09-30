<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\AdminAbility;
use App\Domain\Identity\Enums\PlatformRole;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * 10X platform staff (Super Admin, `admin` guard). Never a tenant member by virtue of this
 * account; tenant access happens only through audited impersonation.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property PlatformRole $role
 * @property ?string $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property ?Carbon $two_factor_confirmed_at
 * @property ?int $two_factor_last_used_step
 * @property ?Carbon $disabled_at
 * @property ?Carbon $last_login_at
 */
class PlatformAdmin extends Authenticatable
{
    use HasUuids, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'last_login_at', 'disabled_at'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return [
            'role' => PlatformRole::class,
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_used_step' => 'integer',
            'last_login_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    /** @return Attribute<string, string> */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value)));
    }

    public function can($abilities, $arguments = []): bool
    {
        if ($abilities instanceof AdminAbility) {
            return $this->isActive() && $this->role->can($abilities);
        }

        return parent::can($abilities, $arguments);
    }

    public function hasTwoFactor(): bool
    {
        return $this->getAttribute('two_factor_confirmed_at') !== null && $this->getAttribute('two_factor_secret') !== null;
    }

    public function isActive(): bool
    {
        return $this->getAttribute('disabled_at') === null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === PlatformRole::SuperAdmin;
    }

    /** @return list<string> */
    public function abilityValues(): array
    {
        return array_map(fn (AdminAbility $a) => $a->value, $this->role->abilities());
    }
}
