<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Identity\Models\User;

/**
 * What a person wants on their phone. Categories map the in-app notification types; problems with a
 * number or the account always come through. "message_text" off: a notification says only who wrote.
 */
final class PushPreferences
{
    public const CATEGORIES = [
        'messages' => ['label' => 'New messages and enquiries', 'hint' => 'A customer writes in a chat that is yours, or a new chat nobody has taken', 'default' => true],
        'alerts' => ['label' => 'Reply-time alerts', 'hint' => 'A customer has waited past a reply-alert step, a 24-hour window is closing, a snooze ended', 'default' => true],
        'assigned' => ['label' => 'Chats given to me', 'hint' => 'Someone assigned or moved a chat to you', 'default' => true],
        'mentions' => ['label' => '@mentions', 'hint' => 'A teammate mentioned you in a note', 'default' => true],
        'account' => ['label' => 'Number and account problems', 'hint' => 'A WhatsApp number disconnected, quality dropped, a template changed category', 'default' => true, 'locked' => true],
    ];

    public static function categoryFor(string $type): string
    {
        return match ($type) {
            'message' => 'messages',
            'assigned', 'reassigned' => 'assigned',
            'mention' => 'mentions',
            'channel_disconnected', 'number_quality', 'template_category', 'flow_review' => 'account',
            default => 'alerts',
        };
    }

    public static function allows(User $user, string $category): bool
    {
        $meta = self::CATEGORIES[$category] ?? null;
        if ($meta === null || ($meta['locked'] ?? false)) {
            return true;
        }
        $stored = $user->getAttribute('push_preferences');
        $choice = is_array($stored) ? ($stored[$category] ?? null) : null;

        return is_bool($choice) ? $choice : $meta['default'];
    }

    public static function messageText(User $user): bool
    {
        $stored = $user->getAttribute('push_preferences');

        return ! is_array($stored) || ($stored['message_text'] ?? true) !== false;
    }

    /** @return array<string, mixed> */
    public static function describe(User $user): array
    {
        $out = [];
        foreach (self::CATEGORIES as $key => $meta) {
            $out[] = ['key' => $key, 'label' => $meta['label'], 'hint' => $meta['hint'], 'enabled' => self::allows($user, $key), 'locked' => $meta['locked'] ?? false];
        }

        return ['categories' => $out, 'message_text' => self::messageText($user)];
    }

    /** @param array<string, mixed> $changes */
    public static function update(User $user, array $changes): void
    {
        $stored = is_array($user->getAttribute('push_preferences')) ? $user->getAttribute('push_preferences') : [];
        foreach ($changes as $key => $value) {
            if ($key === 'message_text' || (isset(self::CATEGORIES[$key]) && ! (self::CATEGORIES[$key]['locked'] ?? false))) {
                $stored[$key] = (bool) $value;
            }
        }
        $user->forceFill(['push_preferences' => $stored])->save();
    }
}
