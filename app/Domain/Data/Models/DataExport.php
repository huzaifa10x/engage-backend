<?php

declare(strict_types=1);

namespace App\Domain\Data\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One CSV export of a workspace's data (Settings › Data), made in the background and kept 7 days.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $kind one of DataExports::KINDS
 * @property string $status queued | running | ready | failed
 * @property ?int $rows
 * @property ?string $disk
 * @property ?string $path
 * @property ?string $error
 * @property ?string $requested_by_membership_id
 * @property ?string $requested_by_name
 * @property ?Carbon $finished_at
 * @property ?Carbon $expires_at
 * @property ?Carbon $created_at
 */
class DataExport extends Model
{
    use BelongsToTenant, HasUuids;

    public const DAYS_KEPT = 7;

    protected $attributes = ['status' => 'queued'];

    protected $fillable = ['tenant_id', 'kind', 'status', 'rows', 'disk', 'path', 'error', 'requested_by_membership_id', 'requested_by_name', 'finished_at', 'expires_at'];

    protected function casts(): array
    {
        return ['rows' => 'integer', 'finished_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
