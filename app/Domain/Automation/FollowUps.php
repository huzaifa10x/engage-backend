<?php

declare(strict_types=1);

namespace App\Domain\Automation;

use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Services\BusinessHours;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Follow-up rules (WhatsApp Engagement Plan v4 §IV), tenants.settings.followups, all editable:
 *
 * Team-side — the team hasn't replied (notifications only, never a customer message):
 *  reply_alert     Needs reply for N minutes in business hours → notify the assigned agent
 *                  (unassigned: the Owners and Admins who can use the number)
 *  escalation      N minutes → notify the chosen teammates (team lead), or reassign to the
 *                  least busy teammate who can reply on the number
 *  window_alert    the 24 h window closes in N minutes with no reply → urgent alert (after that
 *                  only a paid template reaches the customer)
 *  unassigned      unassigned for N minutes → assign by round-robin
 *
 * Customer-side — the customer went quiet (Waiting on customer after a person's reply):
 *  nudge           one nudge after N hours of silence, normal text, only while the 24 h window is
 *                  open (held over quiet hours, skipped if the window would close first)
 *  second_nudge    optional, an approved template later, only for contacts who opted in
 *  weekly_cap      automated messages per contact per 7 days across all automations
 *
 * Every rule runs through the Scheduler and its checks. Customer nudges and escalation rules are
 * part of the Automations feature (Pro); the team alerts come with business hours (Growth).
 */
final class FollowUps
{
    public const TEAM_KINDS = ['reply_alert', 'reply_escalation', 'window_alert', 'unassigned_assign'];

    public const NUDGE_KINDS = ['nudge', 'nudge_template'];

    /** @return array<string, mixed> */
    public static function settings(?Tenant $tenant): array
    {
        $s = (array) (($tenant->settings ?? [])['followups'] ?? []);
        $int = fn (mixed $v, int $default, int $min, int $max) => is_numeric($v) ? max($min, min($max, (int) $v)) : $default;
        $template = is_array($s['second_nudge']['template'] ?? null) && is_string($s['second_nudge']['template']['name'] ?? null) ? [
            'name' => (string) $s['second_nudge']['template']['name'],
            'language' => (string) ($s['second_nudge']['template']['language'] ?? 'en'),
            'body' => array_values(array_map('strval', (array) ($s['second_nudge']['template']['body'] ?? []))),
        ] : null;

        return [
            'reply_alert' => ['enabled' => (bool) ($s['reply_alert']['enabled'] ?? true), 'minutes' => $int($s['reply_alert']['minutes'] ?? null, 10, 1, 1440)],
            'escalation' => [
                'enabled' => (bool) ($s['escalation']['enabled'] ?? false),
                'minutes' => $int($s['escalation']['minutes'] ?? null, 30, 1, 1440),
                'action' => ($s['escalation']['action'] ?? 'notify') === 'reassign' ? 'reassign' : 'notify',
                'notify_ids' => array_values(array_filter((array) ($s['escalation']['notify_ids'] ?? []), 'is_string')),
            ],
            'window_alert' => ['enabled' => (bool) ($s['window_alert']['enabled'] ?? true), 'minutes_before' => $int($s['window_alert']['minutes_before'] ?? null, 120, 15, 720)],
            'unassigned' => ['enabled' => (bool) ($s['unassigned']['enabled'] ?? false), 'minutes' => $int($s['unassigned']['minutes'] ?? null, 5, 1, 1440)],
            'nudge' => [
                'enabled' => (bool) ($s['nudge']['enabled'] ?? false),
                'hours' => $int($s['nudge']['hours'] ?? null, 4, 1, 23),
                'message' => mb_substr((string) ($s['nudge']['message'] ?? 'Hi {{name}}, just checking in — is there anything else we can help you with?'), 0, 1000),
            ],
            'second_nudge' => [
                'enabled' => (bool) ($s['second_nudge']['enabled'] ?? false) && $template !== null,
                'hours' => $int($s['second_nudge']['hours'] ?? null, 24, 1, 168),
                'template' => $template,
            ],
            'weekly_cap' => $int($s['weekly_cap'] ?? (($tenant->settings ?? [])['automation']['weekly_cap'] ?? null), Scheduler::DEFAULT_WEEKLY_CAP, 1, 20),
            // Meta's free service messages per number per month (from 1 October 2026): configurable,
            // because how Meta counts them must be checked against the first invoice.
            'free_service_messages' => $int($s['free_service_messages'] ?? null, 1000, 0, 100000),
        ];
    }

    /**
     * Which rule groups the plan includes.
     *
     * @return array{team: bool, escalation: bool, nudges: bool, round_robin: bool}
     */
    public static function allowed(Tenant $tenant): array
    {
        $set = app(EntitlementService::class)->for($tenant);
        // Customer nudges and escalation rules: Pro (automations beyond the "basic" level).
        $automations = $set->get(FeatureKey::Automations);
        $full = $automations->enabled && ($automations->config['level'] ?? null) !== 'basic';

        return [
            'team' => $set->allows(FeatureKey::BusinessHours),
            'escalation' => $full,
            'nudges' => $full,
            'round_robin' => $set->allows(FeatureKey::AutoRouting),
        ];
    }

    /** A chat started needing a reply: schedule the team-side rules for this wait. */
    public static function waitStarted(Conversation $conversation, Carbon $since, ?Carbon $windowEnds): void
    {
        $context = app(TenantContext::class);
        $tenant = $context->tenantOrNull();
        if ($tenant === null) {
            return;
        }
        $s = self::settings($tenant);
        $can = self::allowed($tenant);
        $scheduler = app(Scheduler::class);
        $number = $conversation->phone_number_id;
        $episode = $since->toIso8601String();
        $key = fn (string $kind) => "{$kind}:{$conversation->id}:{$since->getTimestamp()}";
        $after = fn (int $minutes) => BusinessHours::addSeconds($tenant, $number, $since, $minutes * 60);

        if ($can['team'] && $s['reply_alert']['enabled']) {
            $scheduler->schedule('reply_alert', $after($s['reply_alert']['minutes']), $key('reply_alert'), $conversation, payload: ['episode' => $episode, 'minutes' => $s['reply_alert']['minutes']], priority: 50);
        }
        if ($can['escalation'] && $s['escalation']['enabled']) {
            $scheduler->schedule('reply_escalation', $after($s['escalation']['minutes']), $key('reply_escalation'), $conversation, payload: ['episode' => $episode, 'minutes' => $s['escalation']['minutes']], priority: 50);
        }
        if ($can['team'] && $s['window_alert']['enabled'] && $windowEnds !== null) {
            $due = $windowEnds->copy()->subMinutes($s['window_alert']['minutes_before']);
            $scheduler->schedule('window_alert', $due->max(now()), $key('window_alert'), $conversation, payload: ['episode' => $episode], priority: 40);
        }
        if ($can['round_robin'] && $s['unassigned']['enabled'] && $conversation->assigned_membership_id === null) {
            $scheduler->schedule('unassigned_assign', $after($s['unassigned']['minutes']), $key('unassigned_assign'), $conversation, payload: ['episode' => $episode], priority: 45);
        }
    }

    /** A person answered: the team-side rules for the wait are done. */
    public static function answered(Conversation $conversation): void
    {
        $scheduler = app(Scheduler::class);
        foreach (self::TEAM_KINDS as $kind) {
            $scheduler->cancelForConversation((string) $conversation->id, 'answered', $kind);
        }
    }

    /** A person wrote and now waits on the customer: schedule the nudge (replacing an earlier one). */
    public static function waitingOnCustomer(Conversation $conversation, Carbon $at, string $messageId): void
    {
        $tenant = app(TenantContext::class)->tenantOrNull();
        if ($tenant === null) {
            return;
        }
        $s = self::settings($tenant);
        $scheduler = app(Scheduler::class);
        foreach (self::NUDGE_KINDS as $kind) {
            $scheduler->cancelForConversation((string) $conversation->id, 'rescheduled', $kind);
        }
        if (! self::allowed($tenant)['nudges'] || ! $s['nudge']['enabled']) {
            return;
        }
        $scheduler->schedule('nudge', $at->copy()->addHours($s['nudge']['hours']), "nudge:{$conversation->id}:{$messageId}", $conversation,
            payload: ['after_message' => $messageId, 'cancel_on_reply' => true], priority: 20, sendsMessage: true);
    }
}
