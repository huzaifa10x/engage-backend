<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Crm\Models\CrmStage;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\Models\TenantMembership;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Inbox health (3.5): whether customers got a human reply while their 24-hour window was still open.
 *
 * Each chat whose first customer message in the period is in the last 30 days has one outcome:
 *  - in time: the first human reply (a teammate's message or template that did not fail) came before the
 *    window closed (latest customer message before that reply + 24 h);
 *  - expired: the window closed first, whether or not a reply came later;
 *  - pending: no reply yet and the window is still open.
 * Window expiry rate = expired ÷ (expired + in time). Chats closed as "Not a lead / spam" are left out.
 * Automation's first response is reported apart, so a bot acknowledgement never hides a slow human.
 */
final class InboxHealth
{
    public const DAYS = 30;

    /** @return array<string, mixed> */
    public function report(TenantMembership $membership, ?string $phoneNumberId = null): array
    {
        $from = now()->subDays(self::DAYS);
        $chats = Conversation::query()->select('id')->when($phoneNumberId, fn ($q, $id) => $q->where('phone_number_id', $id));
        app(NumberAccess::class)->scopeChats($chats, $membership);
        $spam = DB::table('conversation_resolutions')->where('reason', 'not_lead')->whereNull('reopened_at')->pluck('conversation_id')->flip();

        $messages = DB::table('messages')->whereIn('conversation_id', $chats)->where('created_at', '>=', $from)
            ->where('origin', '!=', 'history')->orderBy('created_at')
            ->get(['conversation_id', 'direction', 'origin', 'status', 'created_at'])->groupBy('conversation_id');

        $expired = $inTime = $pending = 0;
        $human = $auto = [];
        foreach ($messages as $id => $rows) {
            if (isset($spam[$id])) {
                continue;
            }
            $first = $rows->firstWhere('direction', 'inbound');
            if ($first === null) {
                continue;
            }
            $firstAt = Carbon::parse($first->created_at);
            $reply = $rows->first(fn ($m) => $m->direction === 'outbound' && in_array($m->origin, ['agent', 'app_echo'], true) && $m->status !== 'failed' && Carbon::parse($m->created_at)->gt($firstAt));
            $bot = $rows->first(fn ($m) => $m->direction === 'outbound' && $m->origin === 'automation' && Carbon::parse($m->created_at)->gt($firstAt));
            $replyAt = $reply !== null ? Carbon::parse($reply->created_at) : null;
            $lastIn = $rows->filter(fn ($m) => $m->direction === 'inbound' && ($replyAt === null || Carbon::parse($m->created_at)->lt($replyAt)))->last();
            $windowEnd = Carbon::parse($lastIn->created_at)->addDay();

            if ($replyAt !== null) {
                $replyAt->gt($windowEnd) ? $expired++ : $inTime++;
                $human[] = $firstAt->diffInSeconds($replyAt);
            } elseif (now()->gt($windowEnd)) {
                $expired++;
            } else {
                $pending++;
            }
            if ($bot !== null) {
                $auto[] = $firstAt->diffInSeconds(Carbon::parse($bot->created_at));
            }
        }
        $decided = $expired + $inTime;

        // Sales, kept apart from the response figures.
        $open = DB::table('enquiries')->join('crm_stages', 'crm_stages.id', '=', 'enquiries.stage_id')->where('crm_stages.group', CrmStage::OPEN)
            ->get(['enquiries.id', 'enquiries.value']);
        $withStep = $open->isEmpty() ? 0 : DB::table('next_steps')->whereIn('enquiry_id', $open->pluck('id'))->whereNull('done_at')->whereNull('cancelled_at')
            ->where('due_at', '>', now())->distinct()->count('enquiry_id');

        return [
            'days' => self::DAYS,
            'expired' => $expired,
            'in_time' => $inTime,
            'pending' => $pending,
            'decided' => $decided,
            'rate' => $decided > 0 ? round($expired / $decided, 4) : null,
            'human_first_response' => ['median_seconds' => self::median($human), 'count' => count($human)],
            'automation_first_response' => ['median_seconds' => self::median($auto), 'count' => count($auto)],
            'sales' => [
                'open' => $open->count(),
                'open_with_next_step' => $withStep,
                'open_value' => (float) $open->sum(fn ($e) => (float) ($e->value ?? 0)),
                'open_valued' => $open->whereNotNull('value')->count(),
            ],
        ];
    }

    /** @param list<int|float> $values */
    private static function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return (int) round($n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2);
    }
}
