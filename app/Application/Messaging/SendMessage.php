<?php

declare(strict_types=1);

namespace App\Application\Messaging;

use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Events\MessageStored;
use App\Domain\Messaging\Exceptions\MessagingException;
use App\Domain\Messaging\Jobs\SendWhatsappMessage;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Media;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\ConversationTracker;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * Every outbound message goes through here (inbox reply, API, later campaigns/automations):
 *   idempotency → number connected → contact not opted out → 24h window (free-form only)
 *   → store as `queued` → SendWhatsappMessage (rate limited per number).
 */
final class SendMessage
{
    private const FREE_FORM = ['text', 'image', 'video', 'audio', 'document', 'sticker', 'reaction', 'location', 'interactive'];

    public function __construct(private readonly ConversationTracker $conversations) {}

    /**
     * @param  array{type: string, body?: ?string, media_id?: ?string, template?: ?array<string, mixed>, reply_to?: ?string, content?: ?array<string, mixed>}  $data
     */
    public function toConversation(Conversation $conversation, array $data, MessageOrigin $origin, ?TenantMembership $sender = null, ?string $idempotencyKey = null): Message
    {
        if ($idempotencyKey !== null && ($existing = Message::query()->where('idempotency_key', $idempotencyKey)->first()) !== null) {
            return $existing;
        }

        /** @var PhoneNumber $number */
        $number = $conversation->phoneNumber()->firstOrFail();
        /** @var Contact $contact */
        $contact = $conversation->contact()->firstOrFail();

        if ($number->status !== PhoneNumberStatus::Connected) {
            throw MessagingException::numberUnavailable();
        }
        if ($contact->isOptedOut()) {
            throw MessagingException::optedOut();
        }
        if ($contact->wa_id === null && $contact->bsuid === null) {
            throw MessagingException::noRecipient();
        }
        if (in_array($data['type'], self::FREE_FORM, true) && ! $conversation->isWindowOpen()) {
            throw MessagingException::windowClosed();
        }

        $media = null;
        if (! empty($data['media_id'])) {
            $media = Media::query()->find($data['media_id']); // tenant-scoped
            if ($media === null || ! $media->isReady()) {
                throw MessagingException::mediaNotReady();
            }
        }

        $message = DB::transaction(function () use ($conversation, $number, $contact, $data, $origin, $sender, $idempotencyKey, $media) {
            $content = (array) ($data['content'] ?? []);
            if ($data['type'] === 'reaction' && ! empty($data['reply_to'])) {
                $content['message_id'] = $data['reply_to'];
            }
            if ($media !== null && $data['type'] === 'document') {
                $content['filename'] = $content['filename'] ?? $media->filename;
            }

            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'phone_number_id' => $number->id,
                'contact_id' => $contact->id,
                'direction' => Message::OUTBOUND,
                'origin' => $origin,
                'type' => $data['type'],
                'status' => MessageStatus::Queued,
                'context_wamid' => $data['type'] === 'reaction' ? null : ($data['reply_to'] ?? null),
                'body' => $data['body'] ?? null,
                'content' => $content ?: null,
                'media_id' => $media?->id,
                'template' => $data['template'] ?? null,
                'sent_by_membership_id' => $sender?->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->conversations->apply($message);

            if ($sender !== null && $conversation->assigned_membership_id === null && $origin === MessageOrigin::Agent) {
                $conversation->forceFill(['assigned_membership_id' => $sender->id])->save(); // first responder owns the chat
            }

            return $message;
        });

        SendWhatsappMessage::dispatch($message->id, $number->id, $number->max_mps)->afterCommit();
        MessageStored::dispatch($message, true);

        return $message;
    }

    /** Start (or continue) a thread with a contact from a given number — typically a template. */
    public function toContact(PhoneNumber $number, Contact $contact, array $data, MessageOrigin $origin, ?TenantMembership $sender = null, ?string $idempotencyKey = null): Message
    {
        return $this->toConversation($this->conversations->forContact($number, $contact), $data, $origin, $sender, $idempotencyKey);
    }
}
