<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One thing a store told us (an order was placed, a checkout was left), and what we did about it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $integration_id
 * @property string $event
 * @property string $external_id
 * @property string $status
 * @property ?string $detail
 * @property ?string $phone
 * @property ?string $contact_id
 * @property ?string $message_id
 * @property ?array<string, mixed> $data
 * @property ?Carbon $due_at
 * @property ?Carbon $processed_at
 * @property ?Carbon $created_at
 */
class IntegrationEvent extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'integration_id', 'event', 'external_id', 'status', 'detail', 'phone', 'contact_id', 'message_id', 'data', 'due_at', 'processed_at'];

    protected function casts(): array
    {
        return ['data' => 'array', 'due_at' => 'datetime', 'processed_at' => 'datetime'];
    }

    public function finish(string $status, ?string $detail = null, ?string $messageId = null): void
    {
        $this->forceFill(['status' => $status, 'detail' => $detail !== null ? mb_substr($detail, 0, 300) : null, 'message_id' => $messageId ?? $this->message_id, 'processed_at' => now()])->save();
    }
}
