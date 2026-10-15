<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An API key or token used by API-call nodes as {{secret.NAME}}. The value lives encrypted in the
 * secret store and never leaves the server: flows, the builder and the logs only see the name.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $secret_id
 * @property ?string $hint
 */
class ChatbotSecret extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'name', 'secret_id', 'hint'];
}
