<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The WhatsApp template one store event sends.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $integration_id
 * @property string $event
 * @property bool $enabled
 * @property ?string $phone_number_id
 * @property ?string $template_name
 * @property ?string $template_language
 * @property ?array<string, mixed> $variables {header: [..], body: [..], buttons: {index: ..}} as saved from the portal
 * @property int $delay_minutes
 */
class IntegrationRule extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'integration_id', 'event', 'enabled', 'phone_number_id', 'template_name', 'template_language', 'variables', 'delay_minutes'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'variables' => 'array', 'delay_minutes' => 'integer'];
    }
}
