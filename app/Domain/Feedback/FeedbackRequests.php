<?php

declare(strict_types=1);

namespace App\Domain\Feedback;

use App\Application\Messaging\SendMessage;
use App\Domain\Campaigns\Services\Personalizer;
use App\Domain\Commerce\Models\CommerceOrder;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Timeline\Timeline;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CSAT after a support chat is closed, and feedback / review requests after a delivered order or a
 * completed booking. One request per contact per 7 days at most; a request that is not sent is kept
 * with the reason, so the report shows why. Never blocks closing a chat or recording an order.
 */
final class FeedbackRequests
{
    public const CAP_DAYS = 7;

    /** Button ids of the rating: fb:<request id>:good|ok|poor */
    private const ANSWER = '/^fb:([0-9a-f-]{36}):(good|ok|poor)$/';

    public function __construct(private readonly TenantContext $context, private readonly SendMessage $send) {}

    /** A person closed a chat: ask how it went (CSAT), when it was a support chat and the window is open. */
    public function afterClose(Conversation $conversation, ?string $closedBy): ?FeedbackRequest
    {
        $request = null;
        try {
            $settings = FeedbackSettings::for($this->context->tenantOrNull());
            if (! $settings->csatEnabled || $conversation->closed_at === null) {
                return null;
            }
            /** @var ?Contact $contact */
            $contact = Contact::query()->find($conversation->contact_id);
            if ($contact === null) {
                return null;
            }
            $since = $conversation->getAttribute('opened_at') ?? $conversation->created_at;
            $lastAgent = Message::query()->where('conversation_id', $conversation->id)->where('direction', Message::OUTBOUND)
                ->where('origin', MessageOrigin::Agent->value)->when($since, fn ($q) => $q->where('created_at', '>=', $since))
                ->orderByDesc('created_at')->first(['sent_by_membership_id']);
            if ($lastAgent === null) {
                return null; // no person answered this chat (a chatbot only): not a support chat
            }
            $agent = $conversation->assigned_membership_id ?? $lastAgent->sent_by_membership_id ?? $closedBy;

            $reason = match (true) {
                ! $conversation->isWindowOpen() => 'Outside the 24-hour window',
                $contact->isOptedOut() => 'The customer opted out',
                $this->askedRecently($contact) => 'Asked in the last 7 days',
                default => null,
            };
            $request = $this->claim($contact, $conversation, 'csat', 'conversation_closed',
                'close:'.$conversation->id.':'.$conversation->closed_at->getTimestamp(), $agent, $conversation->phone_number_id, $reason);
            if ($request === null || $reason !== null) {
                return $request;
            }

            $buttons = [];
            foreach (FeedbackRequest::RATINGS as $key => $title) {
                $buttons[] = ['type' => 'reply', 'reply' => ['id' => "fb:{$request->id}:{$key}", 'title' => $title]];
            }
            $message = $this->send->toConversation($conversation, [
                'type' => 'interactive',
                'body' => $settings->csatQuestion,
                'content' => ['type' => 'button', 'body' => ['text' => $settings->csatQuestion], 'action' => ['buttons' => $buttons]],
            ], MessageOrigin::Automation, null, "feedback:{$request->id}");
            // Asking is not a reply from the team: the chat stays closed.
            Conversation::query()->whereKey($conversation->id)->update(['status' => 'closed', 'closed_at' => $conversation->closed_at]);
            $request->forceFill(['message_id' => $message->id])->save();

            return $request;
        } catch (Throwable $e) {
            Log::warning('CSAT request was not sent.', ['conversation_id' => $conversation->id, 'error' => $e->getMessage()]);
            if ($request !== null) {
                $request->forceFill(['status' => 'failed', 'reason' => mb_substr($e->getMessage(), 0, 190)])->save();
            }

            return null;
        }
    }

    /** A WooCommerce order was delivered: a feedback / review request with the approved utility template. */
    public function forOrder(CommerceOrder $order): ?FeedbackRequest
    {
        $settings = FeedbackSettings::for($this->context->tenantOrNull());
        if (! $settings->reviewEnabled || ! $settings->reviewOnOrders || $order->contact_id === null) {
            return null;
        }
        $contact = Contact::query()->find($order->contact_id);

        return $contact === null ? null : $this->review($contact, 'order_delivered', 'order:'.$order->id, ['order.number' => $order->number, 'reference' => $order->number], $order->conversation_id);
    }

    /** A booking was completed (reported by the booking tool through the API). */
    public function forBooking(Contact $contact, string $reference): ?FeedbackRequest
    {
        $settings = FeedbackSettings::for($this->context->tenantOrNull());
        if (! $settings->reviewEnabled || ! $settings->reviewOnBookings) {
            return null;
        }

        return $this->review($contact, 'booking_completed', 'booking:'.mb_substr($reference, 0, 100), ['booking.reference' => $reference, 'reference' => $reference], null);
    }

    /** The customer tapped Good / OK / Poor. Returns true when the message was a rating. */
    public static function isAnswer(Message $message): bool
    {
        return self::answerOf($message) !== null;
    }

    public function answer(Message $message): bool
    {
        $parsed = self::answerOf($message);
        if ($parsed === null) {
            return false;
        }
        [$id, $rating] = $parsed;
        $updated = FeedbackRequest::query()->whereKey($id)->where('contact_id', $message->contact_id)->where('kind', 'csat')->where('status', 'sent')
            ->update(['status' => 'answered', 'rating' => $rating, 'answered_at' => now()]);
        if ($updated === 1) {
            Timeline::record((string) $message->contact_id, 'feedback.answered', Timeline::CUSTOMER, 'feedback_request', $id, ['rating' => FeedbackRequest::RATINGS[$rating]]);
            try {
                $conversation = Conversation::query()->find($message->conversation_id);
                if ($conversation !== null) {
                    $this->send->toConversation($conversation, ['type' => 'text', 'body' => FeedbackSettings::for($this->context->tenantOrNull())->csatThanks],
                        MessageOrigin::Automation, null, "feedback-thanks:{$id}");
                }
            } catch (Throwable $e) {
                Log::info('The thank-you for a rating was not sent.', ['request' => $id, 'error' => $e->getMessage()]);
            }
        }

        return true;
    }

    /** @return ?array{0: string, 1: string} */
    private static function answerOf(Message $message): ?array
    {
        if ($message->direction !== Message::INBOUND || $message->type !== 'interactive') {
            return null;
        }
        $content = (array) ($message->content ?? []);
        $id = (string) (($content['button_reply']['id'] ?? null) ?? '');

        return preg_match(self::ANSWER, $id, $m) === 1 ? [$m[1], $m[2]] : null;
    }

    /** @param array<string, string> $values */
    private function review(Contact $contact, string $source, string $reference, array $values, ?string $conversationId): ?FeedbackRequest
    {
        $settings = FeedbackSettings::for($this->context->tenantOrNull());
        $template = $settings->reviewTemplate;
        $number = $this->numberFor($contact, $conversationId);
        $reason = match (true) {
            $number === null => 'No connected WhatsApp number',
            $contact->isOptedOut() => 'The customer opted out',
            $contact->wa_id === null && $contact->bsuid === null => 'No WhatsApp number',
            $this->askedRecently($contact) => 'Asked in the last 7 days',
            default => null,
        };
        $request = $this->claim($contact, null, 'review', $source, $reference, null, $number?->id, $reason);
        if ($request === null || $reason !== null || $template === null) {
            return $request;
        }

        try {
            $personalizer = app(Personalizer::class);
            $body = array_map(function (string $v) use ($values, $personalizer, $contact): string {
                $v = (string) preg_replace_callback('/\{\{\s*(order\.number|booking\.reference|reference)\s*\}\}/i', fn ($m) => $values[strtolower($m[1])] ?? '', $v);

                return $personalizer->resolve($v, $contact);
            }, $template['body']);
            if (in_array('', $body, true)) {
                $request->forceFill(['status' => 'skipped', 'reason' => 'A template value is empty for this customer'])->save();

                return $request;
            }
            $message = $this->send->toContact($number, $contact, ['type' => 'template',
                'template' => ['name' => $template['name'], 'language' => $template['language'], 'variables' => ['header' => [], 'body' => $body, 'buttons' => []]],
            ], MessageOrigin::Automation, null, "feedback:{$request->id}");
            $request->forceFill(['message_id' => $message->id, 'conversation_id' => $message->conversation_id])->save();
        } catch (Throwable $e) {
            $request->forceFill(['status' => 'failed', 'reason' => mb_substr($e->getMessage(), 0, 190)])->save();
        }

        return $request;
    }

    private function askedRecently(Contact $contact): bool
    {
        return FeedbackRequest::query()->where('contact_id', $contact->id)->whereIn('status', ['sent', 'answered'])
            ->where('created_at', '>=', now()->subDays(self::CAP_DAYS))->exists();
    }

    /** The row first (unique per source and reference): a repeated close or order event never asks twice. */
    private function claim(Contact $contact, ?Conversation $conversation, string $kind, string $source, string $reference, ?string $agent, ?string $numberId, ?string $reason): ?FeedbackRequest
    {
        try {
            return DB::transaction(fn () => FeedbackRequest::query()->create([
                'contact_id' => $contact->id, 'conversation_id' => $conversation?->id, 'phone_number_id' => $numberId, 'kind' => $kind, 'source' => $source,
                'reference' => $reference, 'agent_membership_id' => $agent, 'status' => $reason === null ? 'sent' : 'skipped', 'reason' => $reason,
            ]));
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return null;
            }
            throw $e;
        }
    }

    /** The number the customer last talked to, else the workspace's first connected number. */
    private function numberFor(Contact $contact, ?string $conversationId): ?PhoneNumber
    {
        $conversation = $conversationId !== null ? Conversation::query()->find($conversationId)
            : Conversation::query()->where('contact_id', $contact->id)->orderByDesc('last_message_at')->first();
        $number = $conversation !== null ? PhoneNumber::query()->find($conversation->phone_number_id) : null;
        if ($number !== null && $number->status === PhoneNumberStatus::Connected) {
            return $number;
        }

        return PhoneNumber::query()->where('status', PhoneNumberStatus::Connected->value)->orderBy('created_at')->first();
    }
}
