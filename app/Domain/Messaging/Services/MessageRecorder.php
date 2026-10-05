<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Events\MessageStored;
use App\Domain\Messaging\Jobs\DownloadInboundMedia;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Media;
use App\Domain\Messaging\Models\Message;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single write path for messages that come FROM Meta: customer messages, WhatsApp Business
 * app echoes and imported history. Idempotent on wamid — webhook retries and replays never
 * duplicate a message.
 */
final class MessageRecorder
{
    public function __construct(
        private readonly InboundMessageParser $parser,
        private readonly ConversationTracker $conversations,
        private readonly ConsentService $consent,
    ) {}

    /**
     * @param  array<string, mixed>  $raw  one Cloud API message object
     */
    public function record(PhoneNumber $number, Contact $contact, array $raw, MessageOrigin $origin): ?Message
    {
        $wamid = isset($raw['id']) ? (string) $raw['id'] : null;
        $existing = $wamid !== null ? Message::query()->where('wamid', $wamid)->first() : null;
        if ($existing !== null) {
            // History sends media content later under the placeholder's wamid.
            if ($existing->type === 'media_placeholder' && in_array($raw['type'] ?? null, InboundMessageParser::MEDIA_TYPES, true)) {
                $this->upgradePlaceholder($existing, $number, $raw);
            }

            return null; // already stored
        }

        $type = (string) ($raw['type'] ?? '');

        // Edits and revokes change an existing message rather than adding one.
        if ($type === 'edit' || $type === 'revoke') {
            $this->applyEditOrRevoke($raw, $type);

            return null;
        }

        $parsed = $this->parser->parse($raw);
        $inbound = $origin === MessageOrigin::Customer || ($origin === MessageOrigin::History && ! $this->isFromBusiness($raw, $number));
        $at = isset($raw['timestamp']) && is_numeric($raw['timestamp']) ? Carbon::createFromTimestamp((int) $raw['timestamp']) : now();
        $conversation = $this->conversations->forContact($number, $contact);

        $media = null;
        if ($parsed['media'] !== null && $parsed['media']['meta_media_id'] !== null) {
            $media = Media::query()->create([
                'direction' => $inbound ? 'inbound' : 'outbound',
                'meta_media_id' => $parsed['media']['meta_media_id'],
                'mime_type' => $parsed['media']['mime_type'],
                'sha256' => $parsed['media']['sha256'],
                'filename' => $parsed['media']['filename'],
                'uploaded_to_phone_number_id' => $number->id,
                'status' => 'pending',
            ]);
        }

        try {
            /** @var Message $message */
            $message = DB::transaction(fn () => Message::query()->create([ // savepoint: wamid race
                'conversation_id' => $conversation->id,
                'phone_number_id' => $number->id,
                'contact_id' => $contact->id,
                'direction' => $inbound ? Message::INBOUND : Message::OUTBOUND,
                'origin' => $origin,
                'type' => $parsed['type'],
                'status' => $inbound ? MessageStatus::Received : $this->historyStatus($raw),
                'wamid' => $wamid,
                'context_wamid' => $parsed['context_wamid'],
                'body' => $parsed['body'],
                'content' => $parsed['content'] ?: null,
                'media_id' => $media?->id,
                'meta_timestamp' => $at,
                'sent_at' => $inbound ? null : $at,
            ]));
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return null; // concurrent delivery of the same wamid
            }
            throw $e;
        }

        $this->conversations->apply($message);

        if ($inbound && $origin === MessageOrigin::Customer) {
            $contact->forceFill(['last_inbound_at' => $at])->save();
            $this->applyConsentKeyword($contact, $message);
        }

        if ($media !== null) {
            DownloadInboundMedia::dispatch($media->id)->afterCommit();
        }

        MessageStored::dispatch($message, true);

        return $message;
    }

    /** @param array<string, mixed> $raw */
    private function upgradePlaceholder(Message $message, PhoneNumber $number, array $raw): void
    {
        $parsed = $this->parser->parse($raw);
        $media = Media::query()->create([
            'direction' => $message->direction,
            'meta_media_id' => $parsed['media']['meta_media_id'] ?? null,
            'mime_type' => $parsed['media']['mime_type'] ?? null,
            'sha256' => $parsed['media']['sha256'] ?? null,
            'filename' => $parsed['media']['filename'] ?? null,
            'uploaded_to_phone_number_id' => $number->id,
            'status' => 'pending',
        ]);
        $message->forceFill(['type' => $parsed['type'], 'body' => $parsed['body'], 'content' => $parsed['content'] ?: null, 'media_id' => $media->id])->save();

        if ($media->meta_media_id !== null) {
            DownloadInboundMedia::dispatch($media->id)->afterCommit();
        }
        MessageStored::dispatch($message, false);
    }

    private function applyConsentKeyword(Contact $contact, Message $message): void
    {
        $text = in_array($message->type, ['text', 'button', 'interactive'], true) ? $message->body : null;

        $intent = $this->consent->keywordIntent($text);
        if ($intent === null) {
            return;
        }
        // A tapped button (our in-chat "Subscribe" request) is recorded apart from a typed keyword.
        $source = $message->type === 'text' ? 'keyword' : 'in_chat_button';

        $changed = $intent === 'opt_out'
            ? $this->consent->optOut($contact, $source, $text, $message->id)
            : $this->consent->optIn($contact, $source, $text, $message->id);

        // Confirm once, when the state really changed — never an auto-reply loop.
        if ($changed) {
            app(ConsentAutoReply::class)->confirm($message, $intent);
        }
    }

    /** @param array<string, mixed> $raw */
    private function applyEditOrRevoke(array $raw, string $type): void
    {
        $originalId = $raw[$type]['original_message_id'] ?? null;
        $original = is_string($originalId) ? Message::query()->where('wamid', $originalId)->first() : null;
        if ($original === null) {
            return;
        }

        if ($type === 'revoke') {
            $original->forceFill(['status' => MessageStatus::Deleted, 'revoked_at' => now(), 'body' => null])->save();
        } else {
            $edited = $this->parser->parse((array) ($raw['edit']['message'] ?? []));
            $original->forceFill(['body' => $edited['body'] ?? $original->body, 'edited_at' => now()])->save();
        }

        MessageStored::dispatch($original, false);
    }

    /**
     * History messages: `from` is the business number when the business sent it.
     *
     * @param  array<string, mixed>  $raw
     */
    private function isFromBusiness(array $raw, PhoneNumber $number): bool
    {
        $from = preg_replace('/\D+/', '', (string) ($raw['from'] ?? ''));

        return $from !== '' && $from === preg_replace('/\D+/', '', (string) $number->display_phone_number);
    }

    /** @param array<string, mixed> $raw */
    private function historyStatus(array $raw): MessageStatus
    {
        $status = strtolower((string) ($raw['history_context']['status'] ?? 'sent'));

        return MessageStatus::fromWebhook($status === 'error' ? 'failed' : $status) ?? MessageStatus::Sent;
    }
}
