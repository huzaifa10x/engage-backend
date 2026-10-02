<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Domain\Messaging\Exceptions\MessagingException;
use App\Domain\Messaging\Models\Message;
use App\Support\Api\ErrorCode;

/**
 * Builds the Cloud API request body (minus messaging_product / recipient_type, added by
 * GraphClient) for a stored outbound message. Media must already have a Meta media ID.
 */
final class PayloadBuilder
{
    /** @return array<string, mixed> */
    public function build(Message $message, ?string $metaMediaId): array
    {
        $contact = $message->contact ?? throw MessagingException::noRecipient();
        if ($contact->wa_id === null && $contact->bsuid === null) {
            throw MessagingException::noRecipient();
        }

        $payload = $contact->recipient() + ['type' => $message->type];
        $content = (array) ($message->content ?? []);

        $payload[$message->type] = match ($message->type) {
            'text' => ['body' => (string) $message->body, 'preview_url' => (bool) ($content['preview_url'] ?? true)],
            'image', 'video' => array_filter(['id' => $metaMediaId, 'caption' => $message->body]),
            'document' => array_filter(['id' => $metaMediaId, 'caption' => $message->body, 'filename' => $content['filename'] ?? $message->media?->filename]),
            'audio' => array_filter(['id' => $metaMediaId, 'voice' => ($content['voice'] ?? false) === true ? true : null]),
            'sticker' => ['id' => $metaMediaId],
            'reaction' => ['message_id' => (string) ($content['message_id'] ?? ''), 'emoji' => (string) ($content['emoji'] ?? '')],
            'template' => array_filter([
                'name' => $message->template['name'] ?? null,
                'language' => ['code' => $message->template['language'] ?? 'en'],
                'components' => $this->templateComponents($message, $metaMediaId) ?: null,
            ]),
            'location' => $content,
            'interactive' => $content,
            default => throw new MessagingException("Sending [{$message->type}] messages is not supported.", ErrorCode::ValidationFailed),
        };

        if ($message->context_wamid !== null && $message->type !== 'reaction') {
            $payload['context'] = ['message_id' => $message->context_wamid];
        }

        return $payload;
    }

    /**
     * Stored components, plus the media header (image / video / document) once the file has a
     * Meta media ID for the sending number.
     *
     * @return list<mixed>
     */
    private function templateComponents(Message $message, ?string $metaMediaId): array
    {
        $components = array_values((array) ($message->template['components'] ?? []));
        $type = $message->template['header_media'] ?? null;

        if (is_string($type) && $metaMediaId !== null) {
            $object = array_filter(['id' => $metaMediaId, 'filename' => $type === 'document' ? $message->media?->filename : null]);
            array_unshift($components, ['type' => 'header', 'parameters' => [['type' => $type, $type => $object]]]);
        }

        return $components;
    }
}
