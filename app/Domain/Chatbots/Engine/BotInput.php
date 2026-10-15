<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Engine;

use App\Domain\Messaging\Models\Message;

/** What the customer sent, reduced to what the engine routes on. */
final class BotInput
{
    /** Message types that carry a file. */
    public const MEDIA = ['image', 'video', 'audio', 'document', 'sticker'];

    /**
     * @param  array<string, mixed>  $content  the message content (location, WhatsApp form answers, file details)
     */
    public function __construct(
        public readonly ?string $text,
        public readonly ?string $payload = null,  // id of a tapped button / list row
        public readonly bool $fromAd = false,
        public readonly ?string $type = 'text',
        public readonly array $content = [],
        public readonly ?string $messageId = null,
        public readonly ?string $mediaId = null,
    ) {}

    public static function fromMessage(Message $message): self
    {
        $content = (array) ($message->content ?? []);
        $reply = $content['button_reply'] ?? $content['list_reply'] ?? null;
        $payload = is_array($reply) && isset($reply['id']) ? (string) $reply['id'] : (isset($content['payload']) ? (string) $content['payload'] : null);

        return new self($message->body, $payload, isset($content['referral']), $message->type, $content, $message->id, $message->media_id);
    }

    /** Lower-case, trimmed, without punctuation at the ends: "  Menu! " → "menu". */
    public function normalized(): string
    {
        $t = mb_strtolower(trim((string) $this->text));

        return trim((string) preg_replace('/^[\p{P}\p{S}\s]+|[\p{P}\p{S}\s]+$/u', '', $t));
    }

    /** "2", "٢", "2." → 2 */
    public function number(): ?int
    {
        $t = strtr(trim((string) $this->text), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);

        return preg_match('/^\s*(\d{1,2})\s*[.)]?\s*$/u', $t, $m) === 1 ? (int) $m[1] : null;
    }

    public function isMedia(): bool
    {
        return in_array($this->type, self::MEDIA, true);
    }

    /** A voice note, a sticker, a picture, a location… or a message of only emoji: nothing to read as an answer. */
    public function isNonText(): bool
    {
        if ($this->payload !== null) {
            return false;
        }
        if (! in_array($this->type, ['text', 'button', 'interactive', null], true)) {
            return true;
        }

        return trim((string) $this->text) !== '' && $this->normalized() === '';
    }

    /**
     * The answers of a WhatsApp form (interactive nfm_reply).
     *
     * @return array<string, mixed>|null
     */
    public function formAnswers(): ?array
    {
        $json = $this->content['nfm_reply']['response_json'] ?? null;
        if (! is_string($json)) {
            return null;
        }
        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /** Script of the text, for choosing a language: "ar" for Arabic letters. */
    public function script(): ?string
    {
        $t = (string) $this->text;
        if ($t === '') {
            return null;
        }

        return preg_match('/\p{Arabic}/u', $t) === 1 ? 'ar' : (preg_match('/\p{Latin}/u', $t) === 1 ? 'latin' : null);
    }
}
