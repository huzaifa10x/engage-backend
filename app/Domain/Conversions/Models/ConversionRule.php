<?php

declare(strict_types=1);

namespace App\Domain\Conversions\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A moment that counts as a conversion: a flow step reached or a tag added (LeadSubmitted), or a
 * WooCommerce order linked to the chat (Purchase).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $trigger_type
 * @property ?string $trigger_ref
 * @property string $event_name
 * @property bool $enabled
 */
class ConversionRule extends Model
{
    use BelongsToTenant, HasUuids;

    public const FLOW_STEP = 'flow_step';

    public const TAG = 'tag';

    public const ORDER = 'order';

    public const LEAD = 'LeadSubmitted';

    public const PURCHASE = 'Purchase';

    /** Version 1. ViewContent comes later. */
    public const EVENTS = [self::LEAD, self::PURCHASE];

    protected $fillable = ['tenant_id', 'trigger_type', 'trigger_ref', 'event_name', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
