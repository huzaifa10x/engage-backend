<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A browser or installed app that receives phone notifications for one workspace membership.
 * Kept per person (not tenant-scoped by row-level security); every query filters by membership.
 *
 * @property string $id
 * @property string $user_id
 * @property ?string $tenant_id
 * @property ?string $membership_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $p256dh
 * @property string $auth
 * @property ?string $device_id
 * @property ?string $label
 * @property ?Carbon $test_sent_at
 * @property ?Carbon $test_tapped_at
 * @property ?Carbon $last_sent_at
 * @property int $failures
 * @property ?Carbon $created_at
 */
class PushSubscription extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'tenant_id', 'membership_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'device_id', 'label', 'test_sent_at', 'test_tapped_at', 'last_sent_at', 'failures'];

    protected function casts(): array
    {
        return ['test_sent_at' => 'datetime', 'test_tapped_at' => 'datetime', 'last_sent_at' => 'datetime', 'failures' => 'integer'];
    }

    public static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }
}
