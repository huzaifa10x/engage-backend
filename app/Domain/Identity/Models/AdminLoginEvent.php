<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only login history of the Super Admin panel.
 *
 * @property string $id
 * @property ?string $platform_admin_id
 * @property ?string $email
 * @property string $event
 * @property ?string $method
 * @property ?string $ip
 * @property ?string $country
 * @property ?string $browser
 * @property ?string $os
 * @property ?string $device
 * @property ?Carbon $created_at
 * @property-read ?PlatformAdmin $admin
 */
class AdminLoginEvent extends Model
{
    use HasUuids;

    protected $fillable = ['platform_admin_id', 'email', 'event', 'method', 'ip', 'country', 'user_agent', 'browser', 'os', 'device'];

    public const UPDATED_AT = null;

    /** @return BelongsTo<PlatformAdmin, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'platform_admin_id');
    }
}
