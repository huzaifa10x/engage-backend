<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $order_id
 * @property ?string $product_id
 * @property ?string $sku
 * @property string $name
 * @property int $quantity
 * @property string $total
 */
class CommerceOrderItem extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }
}
