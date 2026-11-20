<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Services;

use App\Domain\Crm\Services\ContactRecord;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every closure of a chat is kept as its own record (Inbox & CRM 3.5): who resolved it, when and why,
 * how it was reopened (the customer wrote, a teammate reopened it, a manager flagged it), and the
 * manager's flag note. The Resolved tab shows one row per closure; "Not a lead" is left out of the
 * response figures, which is why managers can review it.
 */
final class Resolutions
{
    public const REASONS = ['resolved' => 'Resolved', 'not_lead' => 'Not a lead / spam', 'no_action' => 'No action needed'];

    /** A chat was closed. */
    public static function closed(Conversation $conversation, ?string $membershipId, string $reason = 'resolved', ?Carbon $at = null): ?string
    {
        $tenant = app(TenantContext::class)->tenantOrNull();
        if ($tenant === null) {
            return null;
        }
        $id = (string) Str::uuid7();
        DB::table('conversation_resolutions')->insert([
            'id' => $id, 'tenant_id' => $tenant->id, 'conversation_id' => $conversation->id, 'contact_id' => $conversation->contact_id,
            'reason' => array_key_exists($reason, self::REASONS) ? $reason : 'resolved',
            'resolved_by_membership_id' => $membershipId, 'resolved_at' => $at ?? now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(ContactRecord::class)->refresh($conversation->contact_id);

        return $id;
    }

    /** The chat was opened again: the latest closure records how and by whom. */
    public static function reopened(Conversation $conversation, string $how, ?string $membershipId = null): void
    {
        $open = self::current($conversation->id);
        if ($open !== null) {
            DB::table('conversation_resolutions')->where('id', $open->id)->update([
                'reopened_at' => now(), 'reopen_how' => $how, 'reopened_by_membership_id' => $membershipId, 'updated_at' => now(),
            ]);
        }
        app(ContactRecord::class)->refresh($conversation->contact_id);
    }

    /** The closure the chat is in now (not reopened yet). */
    public static function current(string $conversationId): ?object
    {
        return DB::table('conversation_resolutions')->where('conversation_id', $conversationId)->whereNull('reopened_at')
            ->orderByDesc('resolved_at')->first();
    }

    /**
     * Undo straight after resolving: the closure never happened. Only the person who resolved it, within
     * two minutes.
     */
    public static function undo(Conversation $conversation, string $membershipId): bool
    {
        $open = self::current($conversation->id);
        if ($open === null || $open->resolved_by_membership_id !== $membershipId || Carbon::parse($open->resolved_at)->lt(now()->subMinutes(2))) {
            return false;
        }
        DB::table('conversation_resolutions')->where('id', $open->id)->delete();
        app(ContactRecord::class)->refresh($conversation->contact_id);
        DB::table('inbox_response_times')->where('conversation_id', $conversation->id)->where('kind', 'resolution')
            ->where('ended_at', '>=', Carbon::parse($open->resolved_at)->subSecond())->delete();

        return true;
    }

    /** @return array<string, mixed> */
    public static function present(object $r, array $names): array
    {
        return [
            'id' => $r->id,
            'conversation_id' => $r->conversation_id,
            'contact_id' => $r->contact_id,
            'reason' => $r->reason,
            'reason_label' => self::REASONS[$r->reason] ?? 'Resolved',
            'resolved_by' => $r->resolved_by_membership_id !== null ? ['id' => $r->resolved_by_membership_id, 'name' => $names[$r->resolved_by_membership_id] ?? 'Former teammate'] : null,
            'resolved_at' => Carbon::parse($r->resolved_at)->toIso8601String(),
            'reopened_at' => $r->reopened_at !== null ? Carbon::parse($r->reopened_at)->toIso8601String() : null,
            'reopen_how' => $r->reopen_how,
            'reopened_by' => $r->reopened_by_membership_id !== null ? ['id' => $r->reopened_by_membership_id, 'name' => $names[$r->reopened_by_membership_id] ?? 'Former teammate'] : null,
            'flag' => $r->flagged_at !== null ? [
                'by' => ['id' => $r->flagged_by_membership_id, 'name' => $names[$r->flagged_by_membership_id] ?? 'A manager'],
                'note' => $r->flag_note,
                'at' => Carbon::parse($r->flagged_at)->toIso8601String(),
            ] : null,
        ];
    }

    /** @return array<string, string> membership id → name */
    public static function names(): array
    {
        return DB::table('tenant_memberships')->join('users', 'users.id', '=', 'tenant_memberships.user_id')->pluck('users.name', 'tenant_memberships.id')->all();
    }
}
