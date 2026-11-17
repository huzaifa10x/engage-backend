<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A change in a number's standing at Meta: quality rating, messaging limit, flags, display name
 * reviews and account alerts (from webhooks and the hourly re-read). Append-only: never updated or
 * deleted by application code.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $waba_account_id
 * @property ?string $phone_number_id
 * @property string $event_type
 * @property ?string $old_value
 * @property ?string $new_value
 * @property ?array<string, mixed> $payload
 * @property ?Carbon $occurred_at
 * @property ?Carbon $created_at
 */
class QualityEvent extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'waba_account_id', 'phone_number_id', 'event_type', 'old_value', 'new_value', 'payload', 'occurred_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime'];
    }
}
