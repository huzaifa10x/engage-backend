<?php

declare(strict_types=1);

namespace App\Domain\Privacy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $confirmation_code
 * @property string $source
 * @property string $meta_user_id
 * @property string $status
 * @property ?array<string, mixed> $result
 * @property Carbon $requested_at
 * @property ?Carbon $completed_at
 */
class DataDeletionRequest extends Model
{
    use HasUuids;

    protected $fillable = ['confirmation_code', 'source', 'meta_user_id', 'status', 'result', 'requested_at', 'completed_at'];

    protected function casts(): array
    {
        return ['result' => 'array', 'requested_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
