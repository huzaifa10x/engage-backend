<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Handlers;

use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Services\ContactIdentity;
use App\Domain\Messaging\Services\ContactResolver;
use App\Domain\Messaging\Services\MessageRecorder;
use App\Domain\Webhooks\ProcessResult;
use App\Domain\Webhooks\WebhookChange;

/**
 * field `smb_message_echoes` (coexistence): messages the business sent from the WhatsApp
 * Business app. Mirrored into the thread as outbound `app_echo` — they never open a customer
 * service window, never count as unread and never trigger automations.
 */
final class MessageEchoesHandler implements WebhookHandler
{
    public function __construct(
        private readonly ContactResolver $contacts,
        private readonly MessageRecorder $recorder,
    ) {}

    public function fields(): array
    {
        return ['smb_message_echoes'];
    }

    public function handle(WebhookChange $change): ProcessResult
    {
        $number = $change->number;
        if ($number === null) {
            return ProcessResult::Ignored;
        }

        foreach ((array) ($change->value['message_echoes'] ?? []) as $raw) {
            if (! is_array($raw) || isset($raw['group_id'])) {
                continue;
            }

            $identity = new ContactIdentity(
                isset($raw['to']) ? (string) $raw['to'] : null,
                isset($raw['to_user_id']) ? (string) $raw['to_user_id'] : null,
            );
            $contact = $this->contacts->resolve($identity, 'echo');
            if ($contact !== null) {
                $this->recorder->record($number, $contact, $raw, MessageOrigin::AppEcho);
            }
        }

        return ProcessResult::Processed;
    }
}
