<?php

declare(strict_types=1);

namespace App\Domain\Conversions\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The Meta dataset a WhatsApp Business Account reports conversions to. The client owns it; Meta
 * returns the same ID when one already exists.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $waba_account_id
 * @property string $waba_id
 * @property string $dataset_id
 * @property bool $enabled
 */
class MetaDataset extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'waba_account_id', 'waba_id', 'dataset_id', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
