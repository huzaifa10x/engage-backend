<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\Scopes\TenantScope;
use App\Domain\Tenancy\TenantContext;
use App\Notifications\ResetPasswordNotification;
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

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_used_step' => 'integer',
            'last_login_at' => 'datetime',
            'disabled_at' => 'datetime',
            'review_account_at' => 'datetime',
            'sessions_revoked_at' => 'datetime',
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

    /** Password reset link → the web app's reset page (email 2 of the system emails). */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $url = config('engage.frontend_url').'/reset-password?'.http_build_query(['token' => $token, 'email' => $this->email]);
        $this->notify(new ResetPasswordNotification($url, (int) config('auth.passwords.users.expire', 60)));
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /** Two-factor sign-in with an authenticator app is set up and confirmed. */
    public function hasTwoFactor(): bool
    {
        return $this->getAttribute('two_factor_confirmed_at') !== null && $this->getAttribute('two_factor_secret') !== null;
    }

    /** When this browser session signed in (set on every login), kept in the session. */
    public const SIGNED_IN_AT = 'auth.signed_in_at';

    /** A browser session signed in before "Sign out everywhere" (or a password set by support) no longer works. */
    public function sessionRevoked(mixed $signedInAt): bool
    {
        $revoked = $this->getAttribute('sessions_revoked_at');

        return $revoked !== null && (! is_numeric($signedInAt) || (int) $signedInAt <= $revoked->getTimestamp());
    }

    /** Turns two-factor sign-in off: the user's own choice, or a Super Admin reset after a lost phone. */
    public function clearTwoFactor(): void
    {
        $this->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_used_step' => null])->save();
    }

    /** Owner of at least one active workspace: two-factor sign-in is required (engage.auth.owner_two_factor_required). */
    public function ownsAWorkspace(): bool
    {
        return app(TenantContext::class)->bypass(fn () => $this->memberships()->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->where('key', 'owner'))->exists());
    }

    public function twoFactorRequired(): bool
    {
        return ! $this->isReviewAccount() && (bool) config('engage.auth.owner_two_factor_required', true) && $this->ownsAWorkspace();
    }

    /**
     * A review account (e.g. for Meta App Review): email + password only, no authenticator and no
     * emailed code, so a reviewer can sign in unattended. Set by a Super Admin, audited.
     */
    public function isReviewAccount(): bool
    {
        return $this->getAttribute('review_account_at') !== null;
    }
}
