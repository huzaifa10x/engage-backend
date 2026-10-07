<?php

declare(strict_types=1);

namespace App\Domain\Developer\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One event sent (or being sent) to one endpoint, with what came back.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $webhook_endpoint_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property string $status
 * @property int $attempts
 * @property ?int $response_status
 * @property ?string $response_excerpt
 * @property ?int $duration_ms
 * @property ?Carbon $next_attempt_at
 * @property ?Carbon $delivered_at
 * @property ?Carbon $created_at
 * @property ?WebhookEndpoint $endpoint
 */
class WebhookDelivery extends Model
{
    use BelongsToTenant, HasUuids;

    // "webhook_deliveries" already exists: it de-duplicates webhooks RECEIVED from Meta.
    protected $table = 'webhook_endpoint_deliveries';

    protected $fillable = ['tenant_id', 'webhook_endpoint_id', 'event', 'payload', 'status', 'attempts', 'response_status', 'response_excerpt', 'duration_ms', 'next_attempt_at', 'delivered_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'next_attempt_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }
}
