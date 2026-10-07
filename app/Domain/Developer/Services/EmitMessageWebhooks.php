<?php

declare(strict_types=1);

namespace App\Domain\Developer\Services;

use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Events\MessageStored;
use App\Domain\Messaging\Models\Message;

/**
 * Listens to the same event that updates the inbox in real time and turns it into the
 * message.* webhook events: received (a customer wrote) and sent / delivered / read / failed
 * (what happened to a message the workspace sent). Each status is announced once per message.
 */
final class EmitMessageWebhooks
{
    private const STATUS_EVENTS = ['sent' => 'message.sent', 'delivered' => 'message.delivered', 'read' => 'message.read', 'failed' => 'message.failed'];

    public function __construct(private readonly WebhookDispatcher $webhooks) {}

    public function handle(MessageStored $stored): void
    {
        $message = $stored->message;
        if ($message->origin === MessageOrigin::History) {
            return; // imported chat history is not something that "just happened"
        }

        $event = $message->direction === Message::INBOUND
            ? ($stored->created ? 'message.received' : null)
            : (self::STATUS_EVENTS[$message->status->value] ?? null);
        if ($event === null) {
            return;
        }

        $message->loadMissing(['contact', 'media']);
        $this->webhooks->emit($message->tenant_id, $event, PublicPayload::message($message), $message->id);
    }
}
