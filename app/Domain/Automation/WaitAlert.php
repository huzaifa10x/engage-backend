<?php

declare(strict_types=1);

namespace App\Domain\Automation;

use App\Application\Messaging\SendMessage;
use App\Application\WhatsApp\WhatsappCredentials;
use App\Domain\Access\Permission;
use App\Domain\Access\SystemRole;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Services\ReplyPromise;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Infrastructure\Meta\GraphClient;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * One step of the reply promise ladder (3.3, A3): a chat still waits for a person's reply after
 * N business minutes. The assigned agent (unassigned: the Managers), then the Managers, then the
 * Owner, who is also sent a WhatsApp template when one is set up. Every step is logged (wait_alerts),
 * shown on the chat and counted in the weekly report.
 */
final class WaitAlert extends TeamAlert
{
    public function run(?Conversation $conversation, ?Contact $contact, array $payload): string
    {
        if (! $this->stillWaiting($conversation, $payload)) {
            return 'cancelled:answered';
        }
        // The second step (15 min on Fast): move the chat to a free agent, then tell the customer who replies.
        $extra = (int) ($payload['level'] ?? 0) === 2 ? $this->secondStep($conversation, $contact) : [];
        $role = in_array($payload['to'] ?? null, ReplyPromise::ROLES, true) ? (string) $payload['to'] : 'agent';
        $minutes = (int) ($payload['minutes'] ?? 0);
        $to = $this->recipients($conversation, $role);
        $title = "{$this->name($contact)} has waited {$minutes} min for a reply";
        $body = $role === 'owner' ? 'The reply promise is broken. Open the chat or reassign it.' : 'Reply now, or reassign the chat.';
        $channels = ['app', 'email'];
        foreach ($to as $member) {
            $this->tools->notify($member, $role === 'agent' ? 'reply_overdue' : 'reply_escalated', $title, $body, "/inbox?c={$conversation->id}", email: true);
        }

        $whatsappError = null;
        if ($role === 'owner') {
            $whatsappError = $this->whatsappOwner($conversation, $contact, $minutes);
            if ($whatsappError === null) {
                $channels[] = 'whatsapp';
            }
        }

        $tenant = app(TenantContext::class)->tenant();
        DB::table('wait_alerts')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id, 'contact_id' => $conversation->contact_id,
            'episode' => (string) $payload['episode'], 'level' => (int) ($payload['level'] ?? 1), 'minutes' => $minutes, 'role' => $role,
            'recipients' => json_encode($to->map(fn (TenantMembership $m) => ['id' => $m->id, 'name' => $m->user?->name])->values()->all(), JSON_THROW_ON_ERROR),
            'channels' => json_encode($channels, JSON_THROW_ON_ERROR),
            'whatsapp_error' => $whatsappError !== null ? mb_substr($whatsappError, 0, 190) : null,
            'created_at' => now(),
        ]);

        return $to->isEmpty() && ! in_array('whatsapp', $channels, true) && $extra === [] ? 'skipped:nobody_to_tell' : "done:{$role}_".$to->count().($extra !== [] ? ':'.implode(',', $extra) : '');
    }

    /**
     * The second alert step (brief: reply alerts, Fast 5 / 15 / 30). Both are part of Automations (paid):
     *  - reassign: teams of 3 or more people who reply, the chat moves to the least busy one who can use
     *    the number (the wait clock is not reset);
     *  - hold: a holding message to the customer naming the agent, once per wait (this step runs once per
     *    wait); an automation message, so it never counts as a reply and the wait goes on.
     *
     * @return list<string> what was done, for the action's outcome
     */
    private function secondStep(Conversation $conversation, ?Contact $contact): array
    {
        $tenant = app(TenantContext::class)->tenant();
        $s = ReplyPromise::settings($tenant);
        if (! app(EntitlementService::class)->allows($tenant, FeatureKey::Automations)) {
            return [];
        }
        $done = [];

        if ($s['reassign']) {
            $repliers = TenantMembership::query()->active()->with('role')->get()->filter(fn (TenantMembership $m) => $m->role?->grants(Permission::InboxReply) ?? false);
            $pick = $repliers->count() >= ReplyPromise::REASSIGN_MIN_TEAM ? $this->tools->pickAgent($conversation) : null;
            if ($pick !== null && $pick->id !== $conversation->assigned_membership_id) {
                $from = $conversation->assigned_membership_id;
                $conversation->forceFill(['assigned_membership_id' => $pick->id])->save(); // broadcasts conversation.assigned
                $this->tools->notify($pick, 'assigned', "{$this->name($contact)} was moved to you: no reply for {$this->waited($conversation)}", null, "/inbox?c={$conversation->id}", email: true);
                if ($from !== null && ($old = TenantMembership::query()->find($from)) !== null) {
                    $this->tools->notify($old, 'reassigned', "{$this->name($contact)} was moved to {$pick->loadMissing('user')->user?->name} (no reply for {$this->waited($conversation)})", null, "/inbox?c={$conversation->id}");
                }
                $done[] = 'reassigned';
            }
        }

        if ($s['hold'] && $conversation->isWindowOpen()) {
            $agent = $conversation->assigned_membership_id !== null
                ? (string) Str::of((string) TenantMembership::query()->with('user')->find($conversation->assigned_membership_id)?->user?->name)->before(' ')
                : '';
            $customer = (string) Str::of((string) ($contact?->name ?: $contact?->profile_name))->before(' ');
            $body = trim(strtr($s['hold_message'], ['{name}' => $customer !== '' ? $customer : 'for your message', '{agent}' => $agent !== '' ? $agent : 'our team']));
            $body = (string) preg_replace('/^Thanks for your message,/', 'Thanks for your message.', $body);
            try {
                app(SendMessage::class)->toConversation($conversation, ['type' => 'text', 'body' => $body], MessageOrigin::Automation);
                $done[] = 'held';
            } catch (Throwable $e) {
                Log::info('Holding message not sent', ['conversation' => $conversation->id, 'error' => $e->getMessage()]);
            }
        }

        return $done;
    }

    /** @return Collection<int, TenantMembership> */
    private function recipients(Conversation $conversation, string $role): Collection
    {
        $members = TenantMembership::query()->active()->with(['role', 'user'])->get();
        $withRole = fn (SystemRole $r) => $members->filter(fn (TenantMembership $m) => $m->role?->systemRole() === $r)->values();

        if ($role === 'owner') {
            return $withRole(SystemRole::Owner);
        }
        if ($role === 'agent' && $conversation->assigned_membership_id !== null) {
            $assignee = $members->firstWhere('id', $conversation->assigned_membership_id);
            if ($assignee !== null) {
                return collect([$assignee]);
            }
        }
        // Managers who can use this number; none: the Owners and Admins who can.
        $ids = $withRole(SystemRole::Manager)->pluck('id')->all();
        $managers = $ids === [] ? collect() : $this->leads($conversation, $ids);

        /** @var EloquentCollection<int, TenantMembership> $leads */
        $leads = new EloquentCollection(($managers->isNotEmpty() ? $managers : $this->leads($conversation, []))->values()->all());

        return $leads->load('user');
    }

    /** The Owner's WhatsApp alert: an approved template from the chat's number. Returns why it was not sent. */
    private function whatsappOwner(Conversation $conversation, ?Contact $contact, int $minutes): ?string
    {
        $s = ReplyPromise::settings(app(TenantContext::class)->tenant())['owner_whatsapp'];
        if (! $s['ready']) {
            return 'No Owner WhatsApp number or template set up';
        }
        $number = PhoneNumber::query()->with('wabaAccount')->find($conversation->phone_number_id);
        if ($number === null || $number->wabaAccount === null) {
            return 'The number is not connected';
        }
        try {
            $token = app(WhatsappCredentials::class)->tokenFor($number->wabaAccount);
            app(GraphClient::class)->sendMessage($number->phone_number_id, [
                'to' => $s['phone'],
                'type' => 'template',
                'template' => [
                    'name' => $s['template']['name'],
                    'language' => ['code' => $s['template']['language']],
                    'components' => [['type' => 'body', 'parameters' => [
                        ['type' => 'text', 'text' => mb_substr($this->name($contact), 0, 60)],
                        ['type' => 'text', 'text' => (string) $minutes],
                    ]]],
                ],
            ], $token);

            return null;
        } catch (Throwable $e) {
            Log::warning('Owner WhatsApp wait alert failed', ['conversation' => $conversation->id, 'error' => $e->getMessage()]);

            return $e->getMessage();
        }
    }
}
