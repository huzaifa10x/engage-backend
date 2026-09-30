<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $phone_number_id
 * @property string $sync_type
 * @property string $status
 * @property int $progress
 */
class CoexistenceSyncJob extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'phone_number_id', 'sync_type', 'request_id', 'status', 'phase', 'chunk_order', 'progress',
        'chunks_received', 'media_pending', 'error_code', 'error_message', 'requested_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'completed_at' => 'datetime', 'progress' => 'integer'];
    }
}
