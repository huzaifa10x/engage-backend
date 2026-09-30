<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Metadata for a business token. The token itself lives in SecretStore (secret_id).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $secret_id
 * @property ?Carbon $expires_at
 * @property ?Carbon $revoked_at
 */
class MetaAccessToken extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'secret_id', 'token_type', 'meta_app_id', 'meta_subject_id', 'scopes', 'granular_scopes',
        'expires_at', 'data_access_expires_at', 'last_validated_at', 'revoked_at',
    ];

    protected $hidden = ['secret_id'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'granular_scopes' => 'array',
            'expires_at' => 'datetime',
            'data_access_expires_at' => 'datetime',
            'last_validated_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
