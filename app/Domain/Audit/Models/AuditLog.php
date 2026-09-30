<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only, monthly-partitioned (RANGE created_at). tenant_id is NULL for platform-level
 * actions. Not using BelongsToTenant on purpose: reads are always explicitly filtered by the
 * caller, and RLS restricts rows to the active tenant.
 *
 * @property string $id
 * @property ?string $tenant_id
 */
class AuditLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'audit_log';

    protected $fillable = [
        'tenant_id', 'actor_type', 'actor_id', 'action', 'entity_type', 'entity_id',
        'before', 'after', 'meta', 'ip', 'user_agent', 'request_id', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit log entries are immutable; retention drops partitions.'));
    }
}
