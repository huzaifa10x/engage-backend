<?php

declare(strict_types=1);

namespace App\Domain\Automation;

use App\Application\Messaging\SendMessage;
use App\Domain\Messaging\Enums\ConsentState;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Services\ReplyState;
use App\Domain\Tenancy\TenantContext;
use App\Support\Exceptions\DomainException;

/**
 * The optional second nudge: an approved template (usually Marketing, paid per message), only for
 * contacts who opted in; others are skipped and shown as skipped. Meta can still refuse it under its
 * per-user marketing limit (131049): that shows on the message as not delivered.
 */
final class TemplateNudge extends ActionHandler
{
    public function __construct(private readonly SendMessage $send) {}

    public function needsConsent(): bool
    {
        return true;
    }

    public function staysOutOfPersonsWay(): bool
    {
        return true;
    }

    public function cancelsOnReply(): bool
    {
        return true;
    }

    public function respectsQuietHours(): bool
    {
        return true;
    }

    public function run(?Conversation $conversation, ?Contact $contact, array $payload): string
    {
        if ($conversation === null || $contact === null || ReplyState::shown($conversation) !== ReplyState::WAITING) {
            return 'cancelled:not_waiting';
        }
        if ($contact->consent_state !== ConsentState::OptedIn) {
            return 'skipped:no_opt_in';
        }
        $t = FollowUps::settings(app(TenantContext::class)->tenantOrNull())['second_nudge']['template'];
        if ($t === null) {
            return 'skipped:no_template';
        }
        $first = trim((string) strtok($contact->displayName(), ' '));
        try {
            $this->send->toConversation($conversation, ['type' => 'template', 'template' => [
                'name' => $t['name'], 'language' => $t['language'],
                'variables' => ['body' => array_map(fn (string $v) => str_replace(['{{name}}', '{{first_name}}'], $first !== '' ? $first : 'there', $v), $t['body'])],
            ]], MessageOrigin::Automation, null, 'nudge-template:'.($payload['after_message'] ?? $conversation->id));
        } catch (DomainException $e) {
            return 'skipped:'.mb_substr(str_replace(' ', '_', strtolower($e->errorCode()->value)), 0, 40);
        }

        return 'done:sent';
    }
}
