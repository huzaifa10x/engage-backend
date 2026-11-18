<?php

declare(strict_types=1);

namespace App\Domain\Developer\Models;

use App\Domain\Developer\Services\TimelineWebhooks;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A URL on the customer's side that receives events from their workspace.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $url
 * @property ?string $description
 * @property string $secret
 * @property list<string> $events
 * @property string $status
 * @property int $consecutive_failures
 * @property ?Carbon $last_success_at
 * @property ?Carbon $last_failure_at
 * @property ?Carbon $disabled_at
 * @property ?Carbon $created_at
 * @property string $source portal (typed in by a person) or api (created by Zapier, Make or the customer's own code)
 * @property ?string $api_key_id
 */
class WebhookEndpoint extends Model
{
    use BelongsToTenant, HasUuids;

    /** Events that do not come from the contact timeline. */
    private const OTHER_EVENTS = [
        'message.received' => 'A customer sent a message',
        'message.sent' => 'A message you sent left for WhatsApp',
        'message.delivered' => 'A message was delivered to the customer',
        'message.read' => 'A message was read by the customer',
        'message.failed' => 'A message could not be delivered',
        'contact.updated' => 'A contact was changed',
    ];

    /**
     * Every event a customer can subscribe to, with the sentence shown in the portal. Most are read
     * from the contact timeline (TimelineWebhooks); messages and contact edits are sent where they happen.
     *
     * @return array<string, string>
     */
    public static function events(): array
    {
        return self::OTHER_EVENTS + TimelineWebhooks::catalog();
    }

    /** Consecutive failed deliveries after which an endpoint is switched off and the owners are told. */
    public const DISABLE_AFTER_FAILURES = 15;

    /** How many endpoints can be created through the API (each Zapier or Make trigger uses one). */
    public const MAX_VIA_API = 50;

    protected $fillable = ['tenant_id', 'url', 'description', 'secret', 'events', 'status', 'consecutive_failures', 'last_success_at', 'last_failure_at', 'disabled_at', 'source', 'api_key_id'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'events' => 'array', 'last_success_at' => 'datetime', 'last_failure_at' => 'datetime', 'disabled_at' => 'datetime'];
    }

    public function listensTo(string $event): bool
    {
        return in_array($event, $this->events, true);
    }
}
