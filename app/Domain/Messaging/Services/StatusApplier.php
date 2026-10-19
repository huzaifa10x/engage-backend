<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Application\WhatsApp\MarketingMessages;
use App\Domain\Chatbots\Jobs\ChatbotDelivery;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Events\MessageStored;
use App\Domain\Messaging\Exceptions\MessageNotYetKnown;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\MessageStatusEvent;
use Illuminate\Support\Carbon;

/**
 * Applies `statuses[]` webhooks. Meta delivers them out of order and more than once, so:
 *   - every event is recorded (history),
 *   - the message only moves FORWARD (queued < accepted < sent < delivered < read),
 *   - failed wins over queued/accepted/sent but never over delivered/read.
 */
final class StatusApplier
{
    /** @param array<string, mixed> $status one entry of value.statuses[] */
    public function apply(array $status): ?Message
    {
        $wamid = (string) ($status['id'] ?? '');
        $new = MessageStatus::fromWebhook((string) ($status['status'] ?? ''));
        if ($wamid === '' || $new === null) {
            return null;
        }

        $message = Message::query()->where('wamid', $wamid)->lockForUpdate()->first();
        if ($message === null) {
            // The send job may not have stored the wamid yet (status webhook raced the API
            // response). Retry the webhook later instead of dropping the status.
            throw new MessageNotYetKnown($wamid);
        }

        $at = isset($status['timestamp']) && is_numeric($status['timestamp']) ? Carbon::createFromTimestamp((int) $status['timestamp']) : now();

        MessageStatusEvent::query()->create([
            'message_id' => $message->id,
            'status' => $new->value,
            'occurred_at' => $at,
            'payload' => array_intersect_key($status, array_flip(['pricing', 'errors', 'conversation', 'recipient_id', 'recipient_user_id'])) ?: null,
        ]);

        $current = $message->status;
        $updates = [];

        if ($new === MessageStatus::Failed) {
            if (in_array($current, [MessageStatus::Queued, MessageStatus::Accepted, MessageStatus::Sent], true)) {
                $error = (array) ($status['errors'][0] ?? []);
                $updates = [
                    'status' => MessageStatus::Failed,
                    'failed_at' => $at,
                    'error_code' => isset($error['code']) ? (string) $error['code'] : null,
                    'error_title' => isset($error['title']) ? mb_substr((string) $error['title'], 0, 255) : null,
                    'error_details' => $error ?: null,
                ];
            }
        } elseif ($new === MessageStatus::Deleted) {
            $updates = ['status' => MessageStatus::Deleted, 'revoked_at' => $at];
        } elseif ($current !== MessageStatus::Failed && $new->rank() > $current->rank()) {
            $updates['status'] = $new;
        }

        // Timestamps fill in even when the status itself does not move (e.g. read before delivered).
        match ($new) {
            MessageStatus::Sent => $updates['sent_at'] = $message->getAttribute('sent_at') ?? $at,
            MessageStatus::Delivered => $updates['delivered_at'] = $message->getAttribute('delivered_at') ?? $at,
            MessageStatus::Read => $updates['read_at'] = $message->getAttribute('read_at') ?? $at,
            default => null,
        };

        if (isset($status['pricing']) && is_array($status['pricing'])) {
            $updates['pricing'] = $status['pricing'];

            // Meta's pricing shows the path that really delivered it: marketing_lite = Marketing Messages API.
            // Sent to the MM API but priced as plain marketing = Meta delivered it through the Cloud API.
            $category = strtolower((string) ($status['pricing']['category'] ?? ''));
            $route = $message->getAttribute('send_route');
            if ($category === 'marketing_lite' && $route !== MarketingMessages::ROUTE_MM) {
                $updates['send_route'] = MarketingMessages::ROUTE_MM;
            } elseif ($category === 'marketing' && $route === MarketingMessages::ROUTE_MM) {
                $updates['send_route'] = MarketingMessages::ROUTE_MM_FALLBACK;
            }
        }

        if ($updates !== []) {
            $message->forceFill($updates)->save();
            MessageStored::dispatch($message, false);
            // A chatbot template with a "Not delivered" branch waits for this report.
            if (isset($updates['status']) && in_array($updates['status'], [MessageStatus::Failed, MessageStatus::Delivered, MessageStatus::Read], true)
                && str_starts_with((string) $message->getAttribute('idempotency_key'), 'bot:')) {
                ChatbotDelivery::dispatch($message->id)->afterCommit();
            }
        }

        return $message;
    }
}
