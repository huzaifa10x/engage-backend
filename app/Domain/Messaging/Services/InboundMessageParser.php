<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

/**
 * Turns one Cloud API message object (incoming `messages[]`, `message_echoes[]`, or a history
 * thread message) into our message columns. Unknown types are kept verbatim in `content`.
 */
final class InboundMessageParser
{
    public const MEDIA_TYPES = ['image', 'video', 'audio', 'document', 'sticker'];

    /**
     * @param  array<string, mixed>  $m
     * @return array{type: string, body: ?string, content: array<string, mixed>, context_wamid: ?string, media: ?array<string, mixed>}
     */
    public function parse(array $m): array
    {
        $type = (string) ($m['type'] ?? 'unknown');
        $payload = is_array($m[$type] ?? null) ? $m[$type] : [];
        $body = null;
        $media = null;

        switch ($type) {
            case 'text':
                $body = $payload['body'] ?? null;
                break;
            case 'image':
            case 'video':
            case 'audio':
            case 'document':
            case 'sticker':
                $body = $payload['caption'] ?? null;
                $media = [
                    'meta_media_id' => isset($payload['id']) ? (string) $payload['id'] : null,
                    'mime_type' => $payload['mime_type'] ?? null,
                    'sha256' => $payload['sha256'] ?? null,
                    'filename' => $payload['filename'] ?? null,
                ];
                break;
            case 'button':
                $body = $payload['text'] ?? null;
                break;
            case 'interactive':
                $reply = $payload['button_reply'] ?? $payload['list_reply'] ?? $payload['nfm_reply'] ?? [];
                $body = $reply['title'] ?? $reply['body'] ?? null;
                break;
            case 'location':
                $body = trim(($payload['name'] ?? '').' '.($payload['address'] ?? '')) ?: null;
                break;
            case 'reaction':
                $body = null;
                break;
            case 'system':
                $body = $payload['body'] ?? null;
                break;
            case 'unsupported':
            case 'unknown':
                $body = null;
                $payload = ['errors' => $m['errors'] ?? null] + $payload;
                break;
        }

        // History media placeholders carry no content; a later webhook supplies it.
        if ($type === 'media_placeholder') {
            $payload = ['placeholder' => true];
        }

        return [
            'type' => mb_substr($type, 0, 24),
            'body' => is_string($body) ? $body : null,
            'content' => array_filter(
                $payload + ['referral' => $m['referral'] ?? null, 'history_status' => $m['history_context']['status'] ?? null],
                fn ($v) => $v !== null,
            ),
            'context_wamid' => isset($m['context']['id']) ? (string) $m['context']['id'] : null,
            'media' => $media,
        ];
    }
}
