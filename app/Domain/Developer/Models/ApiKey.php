<?php

declare(strict_types=1);

namespace App\Domain\Developer\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A secret that lets a customer's own system call the public API for one workspace.
 * Only a hash is stored; the key is shown once, when it is created.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $prefix
 * @property string $key_hash
 * @property list<string> $scopes
 * @property ?Carbon $expires_at
 * @property ?Carbon $last_used_at
 * @property ?string $last_used_ip
 * @property ?Carbon $revoked_at
 * @property ?Carbon $created_at
 */
class ApiKey extends Model
{
    use BelongsToTenant, HasUuids;

    /** What a key may be allowed to do. A key only gets the scopes it was created with. */
    public const SCOPES = [
        'messages:send' => 'Send messages',
        'messages:read' => 'Read messages and conversations',
        'contacts:read' => 'Read contacts',
        'contacts:write' => 'Create and update contacts, record opt-in and opt-out',
        'templates:read' => 'List message templates and phone numbers',
        'webhooks:manage' => 'Subscribe to events (needed by Zapier and Make triggers)',
    ];

    protected $fillable = ['tenant_id', 'name', 'prefix', 'key_hash', 'scopes', 'expires_at', 'last_used_at', 'last_used_ip', 'revoked_at', 'created_by_membership_id'];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return ['scopes' => 'array', 'expires_at' => 'datetime', 'last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function allows(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }
}
