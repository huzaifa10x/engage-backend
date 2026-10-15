<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Engine;

use App\Domain\Messaging\Models\Message;

/** What the customer sent, reduced to what the engine routes on. */
final class BotInput
{
    public function __construct(
        public readonly ?string $text,
        public readonly ?string $payload = null,  // id of a tapped button / list row
        public readonly bool $fromAd = false,
        public readonly ?string $type = 'text',
    ) {}

    public static function fromMessage(Message $message): self
    {
        $content = (array) ($message->content ?? []);
        $reply = $content['button_reply'] ?? $content['list_reply'] ?? null;
        $payload = is_array($reply) && isset($reply['id']) ? (string) $reply['id'] : (isset($content['payload']) ? (string) $content['payload'] : null);

        return new self($message->body, $payload, isset($content['referral']), $message->type);
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
}
