<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only subscription lifecycle ledger. idempotency_key de-duplicates provider webhooks. */
class SubscriptionEvent extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'subscription_id', 'type', 'from_status', 'to_status', 'payload', 'idempotency_key', 'occurred_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Subscription events are append-only.'));
        static::deleting(fn () => throw new LogicException('Subscription events are append-only.'));
    }
}
