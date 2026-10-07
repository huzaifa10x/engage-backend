<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $phone_number_id
 * @property string $sync_type
 * @property string $status
 * @property int $progress
 * @property int $records_received
 * @property int $records_imported
 * @property ?Carbon $last_imported_at
 * @property ?Carbon $completed_at
 */
class CoexistenceSyncJob extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'phone_number_id', 'sync_type', 'request_id', 'status', 'phase', 'chunk_order', 'progress',
        'chunks_received', 'media_pending', 'error_code', 'error_message', 'requested_at', 'completed_at',
        'records_received', 'records_imported', 'last_imported_at',
    ];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'completed_at' => 'datetime', 'last_imported_at' => 'datetime', 'progress' => 'integer', 'records_received' => 'integer', 'records_imported' => 'integer'];
    }
}
