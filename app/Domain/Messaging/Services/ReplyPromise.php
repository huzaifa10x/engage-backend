<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * The reply promise (Inbox & CRM 3.3, A3, the pilot kit), tenants.settings.promise:
 *
 *  limit_minutes     every enquiry gets a person's reply within this many business minutes (default 60).
 *                    Counted only in business hours, so a 21:00 message is due 60 minutes after opening.
 *  ladder            who is told while a chat still waits: 30 min the assigned agent (unassigned: the
 *                    Managers), 45 min the Managers, 60 min the Owner by WhatsApp and email. Each step
 *                    is logged on the chat (wait_alerts).
 *  owner_whatsapp    the Owner's own WhatsApp number and an approved utility template for the last
 *                    step; without them the Owner is told by email and in the app only.
 *  avg_booking_value what one booking is worth (AED), for "AED at stake" in the weekly report.
 *  baseline          the week-zero audit numbers, entered by hand.
 *
 * Only a person's reply that WhatsApp accepted ends a wait (ReplyState).
 */
final class ReplyPromise
{
    public const DEFAULT_LIMIT = 60;

    public const ROLES = ['agent', 'manager', 'owner'];

    /** @return array<string, mixed> */
    public static function settings(?Tenant $tenant): array
    {
        $s = (array) (($tenant->settings ?? [])['promise'] ?? []);
        $limit = is_numeric($s['limit_minutes'] ?? null) ? max(5, min(1440, (int) $s['limit_minutes'])) : self::DEFAULT_LIMIT;
        $ladder = [];
        foreach ((array) ($s['ladder'] ?? [['minutes' => 30, 'to' => 'agent'], ['minutes' => 45, 'to' => 'manager'], ['minutes' => 60, 'to' => 'owner']]) as $step) {
            if (is_array($step) && is_numeric($step['minutes'] ?? null) && in_array($step['to'] ?? null, self::ROLES, true)) {
                $ladder[] = ['minutes' => max(1, min(1440, (int) $step['minutes'])), 'to' => (string) $step['to']];
            }
        }
        usort($ladder, fn ($a, $b) => $a['minutes'] <=> $b['minutes']);
        $template = is_array($s['owner_whatsapp']['template'] ?? null) && is_string($s['owner_whatsapp']['template']['name'] ?? null) && $s['owner_whatsapp']['template']['name'] !== ''
            ? ['name' => (string) $s['owner_whatsapp']['template']['name'], 'language' => (string) ($s['owner_whatsapp']['template']['language'] ?? 'en')]
            : null;
        $phone = preg_replace('/\D+/', '', (string) ($s['owner_whatsapp']['phone'] ?? '')) ?: null;

        return [
            'enabled' => (bool) ($s['enabled'] ?? true),
            'limit_minutes' => $limit,
            'ladder' => $ladder,
            'owner_whatsapp' => ['phone' => $phone, 'template' => $template, 'ready' => $phone !== null && $template !== null],
            'avg_booking_value' => is_numeric($s['avg_booking_value'] ?? null) ? max(0, (int) $s['avg_booking_value']) : null,
            'baseline' => is_array($s['baseline'] ?? null) ? $s['baseline'] : null,
        ];
    }

    /** When the current wait runs out: the limit added in business time from when it started. */
    public static function dueAt(?Tenant $tenant, ?string $numberId, Carbon $since): Carbon
    {
        return BusinessHours::addSeconds($tenant, $numberId, $since, self::settings($tenant)['limit_minutes'] * 60);
    }

    /**
     * How the chat stands against the promise, for the inbox: waited (business seconds), left (negative
     * when over), the due time, and whether the clock is paused (outside business hours).
     *
     * @return ?array{limit_minutes: int, waited_seconds: int, left_seconds: int, due_at: string, over: bool, paused: bool}
     */
    public static function clock(?Tenant $tenant, Conversation $conversation, ?Carbon $now = null): ?array
    {
        $since = $conversation->getAttribute('needs_reply_since');
        if ($since === null || ReplyState::shown($conversation) !== ReplyState::NEEDS_REPLY) {
            return null;
        }
        $now ??= now();
        $since = Carbon::parse($since);
        $limit = self::settings($tenant)['limit_minutes'] * 60;
        $waited = BusinessHours::seconds($tenant, $conversation->phone_number_id, $since, $now);
        $due = $conversation->getAttribute('reply_due_at') !== null ? Carbon::parse($conversation->getAttribute('reply_due_at')) : self::dueAt($tenant, $conversation->phone_number_id, $since);

        return [
            'limit_minutes' => intdiv($limit, 60),
            'waited_seconds' => $waited,
            'left_seconds' => $limit - $waited,
            'due_at' => $due->toIso8601String(),
            'over' => $waited >= $limit,
            'paused' => ! BusinessHours::isOpen($tenant, $conversation->phone_number_id, $now),
        ];
    }
}
