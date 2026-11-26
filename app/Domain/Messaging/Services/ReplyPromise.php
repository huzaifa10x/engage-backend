<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Carbon;

/**
 * The reply promise (Inbox & CRM 3.3, A3, the pilot kit), tenants.settings.promise:
 *
 *  preset            fast (default) · standard · relaxed · custom. A preset sets the limit and the ladder:
 *                    Fast 5 / 15 / 30 min, Standard 15 / 30 / 45, Relaxed 30 / 60 / 90 (UAE customers
 *                    message several businesses at once and choose whoever answers first).
 *  limit_minutes     every enquiry gets a person's reply within this many business minutes (Fast: 30).
 *                    Counted only in business hours, so a 21:00 message is due after opening.
 *  ladder            who is told while a chat still waits: the assigned agent (unassigned: the Managers),
 *                    then the Managers, then the Owner by WhatsApp and email. Each step is logged on the
 *                    chat (wait_alerts). Custom: as entered.
 *  hold              at the second step, a holding message to the customer naming the agent, once per
 *                    wait; it never counts as a reply (hold_message, {name} and {agent}).
 *  reassign          at the second step, move the chat to the next free agent (teams of 3 or more).
 *  intro             the agent's name in the first reply of each conversation ("Sara here from …").
 *  owner_whatsapp    the Owner's own WhatsApp number and an approved utility template for the last
 *                    step; without them the Owner is told by email and in the app only.
 *  avg_booking_value what one booking is worth (AED), for "AED at stake" in the weekly report.
 *  baseline          the week-zero audit numbers, entered by hand.
 *
 * Only a person's reply that WhatsApp accepted ends a wait (ReplyState).
 */
final class ReplyPromise
{
    /** Business minutes per preset: the alert steps (agent, Managers, Owner); the last is the limit. */
    public const PRESETS = [
        'fast' => [5, 15, 30],
        'standard' => [15, 30, 45],
        'relaxed' => [30, 60, 90],
    ];

    public const DEFAULT_PRESET = 'fast';

    public const DEFAULT_LIMIT = 30;

    public const DEFAULT_HOLD_MESSAGE = 'Thanks {name}, {agent} will reply to you in a few minutes.';

    /** Moving a waiting chat to the next free agent only makes sense with this many people who reply. */
    public const REASSIGN_MIN_TEAM = 3;

    public const ROLES = ['agent', 'manager', 'owner'];

    /** @return array<string, mixed> */
    public static function settings(?Tenant $tenant): array
    {
        $s = (array) (($tenant->settings ?? [])['promise'] ?? []);
        // A workspace that set its own limit or ladder before presets existed keeps them, as Custom.
        $preset = is_string($s['preset'] ?? null) && ($s['preset'] === 'custom' || isset(self::PRESETS[$s['preset']]))
            ? $s['preset']
            : (isset($s['ladder']) || isset($s['limit_minutes']) ? 'custom' : self::DEFAULT_PRESET);
        $steps = self::PRESETS[$preset] ?? self::PRESETS[self::DEFAULT_PRESET];
        $presetLadder = array_map(fn (int $m, string $to) => ['minutes' => $m, 'to' => $to], $steps, self::ROLES);
        $limit = $preset !== 'custom' ? $steps[2] : (is_numeric($s['limit_minutes'] ?? null) ? max(5, min(1440, (int) $s['limit_minutes'])) : self::DEFAULT_LIMIT);
        $ladder = [];
        foreach ((array) ($preset !== 'custom' ? $presetLadder : ($s['ladder'] ?? $presetLadder)) as $step) {
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
            // On only with business hours set: the promise counts business minutes (pilot kit).
            'enabled' => (bool) ($s['enabled'] ?? true) && BusinessHours::enabled($tenant),
            'needs_business_hours' => ! BusinessHours::enabled($tenant),
            'preset' => $preset,
            'limit_minutes' => $limit,
            'ladder' => $ladder,
            'hold' => (bool) ($s['hold'] ?? true),
            'hold_message' => is_string($s['hold_message'] ?? null) && trim($s['hold_message']) !== '' ? mb_substr(trim($s['hold_message']), 0, 300) : self::DEFAULT_HOLD_MESSAGE,
            'reassign' => (bool) ($s['reassign'] ?? true),
            'intro' => (bool) ($s['intro'] ?? true),
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
     * @return ?array{limit_minutes: int, waited_seconds: int, left_seconds: int, due_at: string, over: bool, paused: bool, alert_minutes: list<int>}
     */
    public static function clock(?Tenant $tenant, Conversation $conversation, ?Carbon $now = null): ?array
    {
        $since = $conversation->getAttribute('needs_reply_since');
        if ($since === null || ReplyState::shown($conversation) !== ReplyState::NEEDS_REPLY || ! self::settings($tenant)['enabled']) {
            return null;
        }
        $now ??= now();
        $since = Carbon::parse($since);
        $settings = self::settings($tenant);
        $limit = $settings['limit_minutes'] * 60;
        $waited = BusinessHours::seconds($tenant, $conversation->phone_number_id, $since, $now);
        $due = $conversation->getAttribute('reply_due_at') !== null ? Carbon::parse($conversation->getAttribute('reply_due_at')) : self::dueAt($tenant, $conversation->phone_number_id, $since);

        return [
            'limit_minutes' => intdiv($limit, 60),
            'waited_seconds' => $waited,
            'left_seconds' => $limit - $waited,
            'due_at' => $due->toIso8601String(),
            'over' => $waited >= $limit,
            'paused' => ! BusinessHours::isOpen($tenant, $conversation->phone_number_id, $now),
            // The inbox colours the wait: amber from the first step, red from the second (Fast: 5 and 15).
            'alert_minutes' => array_values(array_map(fn (array $step) => $step['minutes'], $settings['ladder'])),
        ];
    }
}
