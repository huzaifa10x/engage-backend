<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Keeps conversation state consistent with its messages: the thread row, unread counter,
 * last-message preview and — most importantly — the customer service window, which ONLY an
 * inbound customer message opens (never app echoes, history imports or our own sends).
 */
final class ConversationTracker
{
    public function forContact(PhoneNumber $number, Contact $contact): Conversation
    {
        $existing = Conversation::query()->where('phone_number_id', $number->id)->where('contact_id', $contact->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(fn () => Conversation::query()->create([ // savepoint
                'phone_number_id' => $number->id,
                'contact_id' => $contact->id,
                'status' => ConversationStatus::Open,
            ]));
        } catch (QueryException $e) {
            if ($e->getCode() !== '23505') {
                throw $e;
            }

            return Conversation::query()->where('phone_number_id', $number->id)->where('contact_id', $contact->id)->firstOrFail();
        }
    }

    /** Apply a newly stored message to its conversation (locked row, so counters never race). */
    public function apply(Message $message): Conversation
    {
        /** @var Conversation $conversation */
        $conversation = Conversation::query()->whereKey($message->conversation_id)->lockForUpdate()->firstOrFail();
        $at = $message->meta_timestamp ?? $message->created_at ?? now();
        $updates = [];

        $isNewest = $conversation->last_message_at === null || $at->gte($conversation->last_message_at);
        if ($isNewest && $message->type !== 'reaction') {
            $updates += [
                'last_message_at' => $at,
                'last_message_preview' => $message->preview(),
                'last_message_direction' => $message->direction,
            ];
        }

        if ($message->direction === Message::INBOUND && $message->origin === MessageOrigin::Customer) {
            if ($conversation->last_inbound_at === null || $at->gte($conversation->last_inbound_at)) {
                $updates['last_inbound_at'] = $at;
                $updates['window_expires_at'] = Carbon::instance($at)->addHours((int) config('engage.messaging.window_hours', 24));
            }
            $updates['unread_count'] = $conversation->unread_count + 1;
            if ($conversation->status === ConversationStatus::Closed) {
                $updates += ['status' => ConversationStatus::Open, 'closed_at' => null];
            }
        }

        if ($updates !== []) {
            $conversation->forceFill($updates)->save();
        }

        return $conversation;
    }
}
