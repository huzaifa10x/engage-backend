<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\Permission;
use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Messaging\Events\ConversationAssigned;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\MemberNotification;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Notifications\InboxAlertNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Business hours, auto-routing of new conversations and member notifications — the parts of the
 * inbox that run by themselves. All of it is best effort: none of it may break message intake.
 */
final class InboxTools
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementService $entitlements,
        private readonly NumberAccess $access,
    ) {}

    /**
     * The inbox settings (tenants.settings.inbox), with defaults:
     *  business_hours  the workspace's week; weekend = days switched off
     *  number_hours    optional: a different week for some WhatsApp numbers (phone number id → week)
     *  holidays        closed dates (Eid, National Day…), added in advance
     *  reply_target_minutes  first-response target for "answered within target" (business hours)
     *  window_warning_minutes  "Reply before window closes" this long before the 24 h window ends
     *
     * @return array{business_hours: array{enabled: bool, days: array<string, array{open: bool, from: string, to: string}>}, number_hours: array<string, array<string, array{open: bool, from: string, to: string}>>, holidays: list<array{date: string, name: string}>, routing: string, timezone: string, reply_target_minutes: int, reply_warn_minutes: int, window_warning_minutes: int}
     */
    public static function settings(?Tenant $tenant): array
    {
        $stored = (array) (($tenant->settings ?? [])['inbox'] ?? []);
        $numberHours = [];
        foreach ((array) ($stored['number_hours'] ?? []) as $numberId => $week) {
            if (is_string($numberId) && is_array($week)) {
                $numberHours[$numberId] = self::week(['days' => $week]);
            }
        }
        $holidays = [];
        foreach ((array) ($stored['holidays'] ?? []) as $h) {
            $date = is_array($h) ? ($h['date'] ?? null) : null;
            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                $holidays[] = ['date' => $date, 'name' => mb_substr((string) ($h['name'] ?? ''), 0, 60)];
            }
        }
        usort($holidays, fn (array $a, array $b) => strcmp($a['date'], $b['date']));

        return [
            'business_hours' => ['enabled' => (bool) ($stored['business_hours']['enabled'] ?? false), 'days' => self::week((array) ($stored['business_hours'] ?? []))],
            'number_hours' => $numberHours,
            'holidays' => $holidays,
            'routing' => ($stored['routing'] ?? 'manual') === 'round_robin' ? 'round_robin' : 'manual',
            'timezone' => BusinessHours::timezone($tenant),
            'reply_target_minutes' => max(1, min(1440, (int) ($stored['reply_target_minutes'] ?? 10))),
            // The Needs reply timer: grey until this, amber until the target, red after it.
            'reply_warn_minutes' => max(0, min(1440, (int) ($stored['reply_warn_minutes'] ?? 5))),
            'window_warning_minutes' => max(15, min(720, (int) ($stored['window_warning_minutes'] ?? 120))),
        ];
    }

    /**
     * @param  array<string, mixed>  $stored  ['days' => [...]]
     * @return array<string, array{open: bool, from: string, to: string}>
     */
    private static function week(array $stored): array
    {
        $days = [];
        foreach (self::DAYS as $day) {
            $d = (array) ($stored['days'][$day] ?? []);
            $days[$day] = [
                'open' => (bool) ($d['open'] ?? ! in_array($day, ['sat', 'sun'], true)),
                'from' => self::clock($d['from'] ?? null, '09:00'),
                'to' => self::clock($d['to'] ?? null, '18:00'),
            ];
        }

        return $days;
    }

    private static function clock(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : $default;
    }

    /** True when business hours are not in use, or it is inside them (holidays and per-number hours included). */
    public static function isOpen(?Tenant $tenant, ?Carbon $at = null, ?string $numberId = null): bool
    {
        return BusinessHours::isOpen($tenant, $numberId, $at);
    }

    /**
     * Auto-routing: an unassigned conversation that just received a customer message goes to the
     * teammate who can reply on that number and currently has the fewest open conversations.
     */
    public function route(Conversation $conversation): void
    {
        try {
            $tenant = $this->context->tenantOrNull();
            if ($tenant === null || $conversation->assigned_membership_id !== null || self::settings($tenant)['routing'] !== 'round_robin'
                || ! $this->entitlements->for($tenant)->allows(FeatureKey::AutoRouting)) {
                return;
            }
            $pick = $this->pickAgent($conversation);
            if ($pick === null) {
                return;
            }

            $claimed = Conversation::query()->whereKey($conversation->id)->whereNull('assigned_membership_id')->update(['assigned_membership_id' => $pick->id]);
            if ($claimed === 1) {
                ConversationAssigned::dispatch((string) $conversation->tenant_id, (string) $conversation->id, (string) $conversation->phone_number_id, null, $pick->id);
                $this->notify($pick, 'assigned', 'A new conversation was assigned to you', $conversation->contact?->displayName(), "/inbox?c={$conversation->id}", email: true);
            }
        } catch (Throwable $e) {
            Log::warning('Auto-routing failed', ['conversation' => $conversation->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The teammate who can reply on the conversation's number and has the fewest open
     * conversations right now (auto-routing, and chatbots handing a customer to a person), optionally
     * only among one inbox team's members.
     */
    public function pickAgent(Conversation $conversation, ?string $teamId = null): ?TenantMembership
    {
        $number = PhoneNumber::query()->find($conversation->phone_number_id);
        if ($number === null) {
            return null;
        }

        $candidates = TenantMembership::query()->active()->with('role')
            // A team handover: only that team's members (InboxTeam).
            ->when($teamId !== null, fn ($q) => $q->whereIn('id', DB::table('inbox_team_members')->where('team_id', $teamId)->select('membership_id')))
            ->get()->filter(function (TenantMembership $m) use ($number): bool {
                if ($m->role === null || ! $m->role->grants(Permission::InboxReply)) {
                    return false;
                }
                try {
                    $this->access->ensureCanAccess($m, $number);

                    return true;
                } catch (Throwable) {
                    return false;
                }
            });
        if ($candidates->isEmpty()) {
            return null;
        }

        $load = Conversation::query()->where('status', ConversationStatus::Open->value)->whereIn('assigned_membership_id', $candidates->pluck('id'))
            ->selectRaw('assigned_membership_id, count(*) AS total')->groupBy('assigned_membership_id')->toBase()->pluck('total', 'assigned_membership_id');

        /** @var TenantMembership */
        return $candidates->sortBy(fn (TenantMembership $m) => [(int) ($load[$m->id] ?? 0), $m->id])->first();
    }

    /** In-app notification (the bell), optionally with an email copy. */
    public function notify(TenantMembership $member, string $type, string $title, ?string $body, string $url, bool $email = false): void
    {
        try {
            MemberNotification::query()->create([
                'membership_id' => $member->id, 'type' => $type, 'title' => mb_substr($title, 0, 190),
                'body' => $body !== null ? mb_substr($body, 0, 300) : null, 'url' => $url,
            ]);
            $user = $email ? $member->user()->first() : null;
            if ($user !== null) {
                $user->notify(new InboxAlertNotification($title, $body !== null ? mb_substr($body, 0, 300) : null, config('engage.frontend_url').$url));
            }
        } catch (Throwable $e) {
            Log::warning('Member notification failed', ['member' => $member->id, 'error' => $e->getMessage()]);
        }
    }
}
