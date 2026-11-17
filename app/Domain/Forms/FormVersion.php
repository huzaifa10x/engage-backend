<?php

declare(strict_types=1);

namespace App\Domain\Forms;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $form_id
 * @property int $version
 * @property array<string, mixed> $definition
 * @property ?string $meta_flow_id
 * @property string $meta_status NOT_CREATED | DRAFT | PUBLISHED | DEPRECATED | BLOCKED | THROTTLED
 * @property ?list<array<string, mixed>> $meta_errors
 * @property ?Carbon $published_at
 * @property ?Carbon $checked_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class FormVersion extends Model
{
    use BelongsToTenant, HasUuids;

    protected $attributes = ['meta_status' => 'NOT_CREATED'];

    protected $fillable = ['tenant_id', 'form_id', 'version', 'definition', 'meta_flow_id', 'meta_status', 'meta_errors', 'published_at', 'checked_at'];

    protected function casts(): array
    {
        return ['definition' => 'array', 'meta_errors' => 'array', 'version' => 'integer', 'published_at' => 'datetime', 'checked_at' => 'datetime'];
    }

    /** Published, deprecated, blocked: Meta no longer lets this version change. */
    public function locked(): bool
    {
        return in_array($this->meta_status, ['PUBLISHED', 'DEPRECATED', 'BLOCKED', 'THROTTLED'], true);
    }
}
