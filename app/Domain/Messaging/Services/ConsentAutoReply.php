<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Application\Messaging\SendMessage;
use App\Domain\Compliance\ComplianceSettings;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Unsubscribed. Reply START to resume." / "You're subscribed." — sent inside the 24-hour window
 * the customer's own keyword just opened. Recording the consent never depends on this reply.
 */
final class ConsentAutoReply
{
    public function __construct(private readonly TenantContext $context) {}

    /** @param 'opt_in'|'opt_out' $intent */
    public function confirm(Message $inbound, string $intent): void
    {
        $settings = ComplianceSettings::for($this->context->tenantOrNull());
        [$enabled, $text] = $intent === 'opt_out' ? [$settings->confirmOptOut, $settings->optOutReply] : [$settings->confirmOptIn, $settings->optInReply];
        if (! $enabled || trim($text) === '') {
            return;
        }

        try {
            $conversation = Conversation::query()->find($inbound->conversation_id);
            if ($conversation !== null) {
                app(SendMessage::class)->toConversation($conversation, ['type' => 'text', 'body' => $text, 'content' => ['preview_url' => false]],
                    MessageOrigin::Automation, null, "consent-reply:{$inbound->id}", consentNotice: true);
            }
        } catch (Throwable $e) {
            Log::warning('Consent confirmation could not be sent.', ['message_id' => $inbound->id, 'error' => $e->getMessage()]);
        }
    }
}
