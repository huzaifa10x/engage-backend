<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Domain\Identity\Models\User;
use App\Notifications\Contracts\HasEmailCategory;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Validation\ValidationException;

/**
 * Which emails each person receives. Everything is on by default; only the categories a person
 * switches off are stored (users.email_preferences = {"inbox_mentions": false}).
 *
 * Enforced in one place, when an email is about to be sent (NotificationSending), so a queued email
 * follows the preference at the moment it goes out. Workspace emails are addressed to an email
 * address rather than an account; when that address belongs to an account, its preferences apply.
 * Sign-in codes and other security emails have no category and can never be switched off.
 */
final class EmailPreferences
{
    public const SECURITY = 'security';

    /** @var array<string, array{label: string, description: string, group: string, owners_only?: bool, locked?: bool}> */
    public const CATEGORIES = [
        self::SECURITY => [
            'label' => 'Sign-in and security',
            'description' => 'Sign-in codes (OTP), email verification, password resets and changes to your sign-in security. Always sent to protect your account.',
            'group' => 'Account',
            'locked' => true,
        ],
        'inbox_assigned' => [
            'label' => 'Conversations assigned to me',
            'description' => 'A teammate, an assignment rule or a chatbot hands a conversation to you.',
            'group' => 'Inbox',
        ],
        'inbox_mentions' => [
            'label' => 'Mentions',
            'description' => 'Someone mentions you in an internal note.',
            'group' => 'Inbox',
        ],
        'inbox_alerts' => [
            'label' => 'Reply-time alerts',
            'description' => 'A customer’s 24-hour window is about to close, or a conversation was escalated because nobody replied.',
            'group' => 'Inbox',
        ],
        'whatsapp_numbers' => [
            'label' => 'WhatsApp numbers',
            'description' => 'A number was connected, or must be reconnected to keep sending and receiving.',
            'group' => 'Workspace',
            'owners_only' => true,
        ],
        'templates' => [
            'label' => 'Template reviews',
            'description' => 'Meta rejected one of your message templates.',
            'group' => 'Workspace',
            'owners_only' => true,
        ],
        'developer' => [
            'label' => 'Webhook alerts',
            'description' => 'A webhook endpoint was switched off after repeated failed deliveries.',
            'group' => 'Workspace',
            'owners_only' => true,
        ],
        'billing' => [
            'label' => 'Billing',
            'description' => 'Payment receipts and failed payments.',
            'group' => 'Workspace',
            'owners_only' => true,
        ],
    ];

    /** Inbox alert types (member notifications) → their email category. */
    public static function forInboxType(string $type): string
    {
        return match ($type) {
            'assigned' => 'inbox_assigned',
            'mention' => 'inbox_mentions',
            default => 'inbox_alerts',
        };
    }

    public function allows(User $user, string $category): bool
    {
        if (! isset(self::CATEGORIES[$category]) || (self::CATEGORIES[$category]['locked'] ?? false)) {
            return true;
        }
        $stored = $user->getAttribute('email_preferences');

        return ! (is_array($stored) && ($stored[$category] ?? true) === false);
    }

    /** @return list<array{key: string, label: string, description: string, group: string, owners_only: bool, locked: bool, enabled: bool}> */
    public function list(User $user): array
    {
        $out = [];
        foreach (self::CATEGORIES as $key => $c) {
            $out[] = [
                'key' => $key,
                'label' => $c['label'],
                'description' => $c['description'],
                'group' => $c['group'],
                'owners_only' => $c['owners_only'] ?? false,
                'locked' => $c['locked'] ?? false,
                'enabled' => $this->allows($user, $key),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $changes  category => on/off
     *
     * @throws ValidationException
     */
    public function update(User $user, array $changes): void
    {
        $stored = is_array($user->getAttribute('email_preferences')) ? $user->getAttribute('email_preferences') : [];
        foreach ($changes as $key => $on) {
            if (! isset(self::CATEGORIES[$key])) {
                throw ValidationException::withMessages(["preferences.{$key}" => 'This is not an email notification type.']);
            }
            if (self::CATEGORIES[$key]['locked'] ?? false) {
                if ($on === false) {
                    throw ValidationException::withMessages(["preferences.{$key}" => 'Sign-in and security emails, including sign-in codes, cannot be turned off.']);
                }

                continue;
            }
            if ($on) {
                unset($stored[$key]);
            } else {
                $stored[$key] = false;
            }
        }
        $user->forceFill(['email_preferences' => $stored === [] ? null : $stored])->save();
    }

    /** NotificationSending listener: false stops this email for this recipient. */
    public function handle(NotificationSending $event): ?bool
    {
        if ($event->channel !== 'mail' || ! $event->notification instanceof HasEmailCategory) {
            return null;
        }
        $user = $this->recipient($event->notifiable);

        return $user !== null && ! $this->allows($user, $event->notification->emailCategory()) ? false : null;
    }

    private function recipient(mixed $notifiable): ?User
    {
        if ($notifiable instanceof User) {
            return $notifiable;
        }
        if ($notifiable instanceof AnonymousNotifiable) {
            $route = $notifiable->routes['mail'] ?? null;
            $email = is_array($route) ? array_key_first($route) : $route;

            return is_string($email) && $email !== '' ? User::query()->where('email', mb_strtolower(trim($email)))->first() : null;
        }

        return null;
    }
}
