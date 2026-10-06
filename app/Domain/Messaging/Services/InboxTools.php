<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\Permission;
use App\Domain\Messaging\Enums\ConversationStatus;
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
     * @return array{business_hours: array{enabled: bool, days: array<string, array{open: bool, from: string, to: string}>}, routing: string, timezone: string}
     */
    public static function settings(?Tenant $tenant): array
    {
        $stored = (array) (($tenant->settings ?? [])['inbox'] ?? []);
        $days = [];
        foreach (self::DAYS as $day) {
            $d = (array) ($stored['business_hours']['days'][$day] ?? []);
            $days[$day] = [
                'open' => (bool) ($d['open'] ?? ! in_array($day, ['sat', 'sun'], true)),
                'from' => self::clock($d['from'] ?? null, '09:00'),
                'to' => self::clock($d['to'] ?? null, '18:00'),
            ];
        }

        return [
            'business_hours' => ['enabled' => (bool) ($stored['business_hours']['enabled'] ?? false), 'days' => $days],
            'routing' => ($stored['routing'] ?? 'manual') === 'round_robin' ? 'round_robin' : 'manual',
            'timezone' => $tenant->timezone ?? 'UTC',
        ];
    }

    private static function clock(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : $default;
    }

    /** True when business hours are not in use, or it is currently inside them (workspace time zone). */
    public static function isOpen(?Tenant $tenant, ?Carbon $at = null): bool
    {
        $settings = self::settings($tenant);
        if (! $settings['business_hours']['enabled']) {
            return true;
        }
        $local = ($at ?? now())->copy()->setTimezone($settings['timezone']);
        $day = $settings['business_hours']['days'][self::DAYS[$local->dayOfWeekIso - 1]];
        $time = $local->format('H:i');

        return $day['open'] && $time >= $day['from'] && $time < $day['to'];
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
            $number = PhoneNumber::query()->find($conversation->phone_number_id);
            if ($number === null) {
                return;
            }

            $candidates = TenantMembership::query()->active()->with('role')->get()->filter(function (TenantMembership $m) use ($number): bool {
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
                return;
            }

            $load = Conversation::query()->where('status', ConversationStatus::Open->value)->whereIn('assigned_membership_id', $candidates->pluck('id'))
                ->selectRaw('assigned_membership_id, count(*) AS total')->groupBy('assigned_membership_id')->toBase()->pluck('total', 'assigned_membership_id');
            /** @var TenantMembership $pick */
            $pick = $candidates->sortBy(fn (TenantMembership $m) => [(int) ($load[$m->id] ?? 0), $m->id])->first();

            $claimed = Conversation::query()->whereKey($conversation->id)->whereNull('assigned_membership_id')->update(['assigned_membership_id' => $pick->id]);
            if ($claimed === 1) {
                $this->notify($pick, 'assigned', 'A new conversation was assigned to you', $conversation->contact?->displayName(), "/inbox?c={$conversation->id}", email: true);
            }
        } catch (Throwable $e) {
            Log::warning('Auto-routing failed', ['conversation' => $conversation->id, 'error' => $e->getMessage()]);
        }
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
