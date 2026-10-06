<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One signed-in browser of a platform admin.
 *
 * @property string $id
 * @property string $platform_admin_id
 * @property string $session_hash
 * @property ?string $ip
 * @property ?string $country
 * @property ?string $user_agent
 * @property ?string $browser
 * @property ?string $os
 * @property ?string $device
 * @property Carbon $last_active_at
 * @property ?Carbon $revoked_at
 * @property ?Carbon $created_at
 * @property-read ?PlatformAdmin $admin
 */
class AdminSession extends Model
{
    use HasUuids;

    protected $fillable = ['platform_admin_id', 'session_hash', 'ip', 'country', 'user_agent', 'browser', 'os', 'device', 'last_active_at', 'revoked_at'];

    protected function casts(): array
    {
        return ['last_active_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<PlatformAdmin, $this> */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'platform_admin_id');
    }
}
