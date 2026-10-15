<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Engine;

use App\Application\Messaging\ManageContacts;
use App\Application\Messaging\SendMessage;
use App\Domain\Chatbots\Models\ChatbotEvent;
use App\Domain\Chatbots\Models\ChatbotSecret;
use App\Domain\Chatbots\Models\ChatbotSession;
use App\Domain\Crm\Models\Segment;
use App\Domain\Crm\Services\SegmentQuery;
use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Exceptions\MessagingException;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Services\InboxTools;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Infrastructure\Secrets\SecretStore;
use App\Support\Api\ErrorCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A real conversation. The rule that matters most lives in send(): immediately before every
 * bot message the conversation row is locked and the handler re-read. If an agent replied (or
 * took over, or the WhatsApp Business app sent something) while the bot was working, the
 * handler is no longer "bot" and the stale bot reply is discarded, never sent.
 */
final class LiveRuntime extends BotRuntime
{
    private Contact $contactModel;

    public function __construct(
        private readonly ChatbotSession $session,
        private readonly Conversation $conversation,
        Contact $contact,
    ) {
        $this->contactModel = $contact;
        $this->state = [
            'current_node' => $session->current_node,
            'awaiting_input' => $session->awaiting_input,
            'retry_count' => $session->retry_count,
            'vars' => (array) ($session->vars ?? []),
            'status' => $session->status,
            'steps' => $session->steps,
            'resume_at' => $session->resume_at?->toIso8601String(),
            'ended_reason' => null,
            'ended_node' => null,
        ];
        $this->contact = self::snapshot($contact);
    }

    /** @return array{id: ?string, name: ?string, first_name: ?string, email: ?string, phone: ?string, fields: array<string, mixed>, tags: list<string>, consent: string} */
    public static function snapshot(Contact $contact): array
    {
        $name = $contact->name ?? $contact->profile_name;

        return [
            'id' => $contact->id,
            'name' => $name,
            'first_name' => $name !== null ? (explode(' ', trim($name))[0] ?: null) : null,
            'email' => $contact->email,
            'phone' => $contact->wa_id !== null ? '+'.$contact->wa_id : null,
            'fields' => (array) ($contact->custom_fields ?? []),
            'tags' => (array) ($contact->tags ?? []),
            'consent' => $contact->consent_state->value,
        ];
    }

    public function send(array $data): void
    {
        $key = "bot:{$this->session->id}:".(++$this->state['steps']);

        try {
            DB::transaction(function () use ($data, $key): void {
                /** @var ?Conversation $conversation */
                $conversation = Conversation::query()->whereKey($this->conversation->id)->lockForUpdate()->first();
                if ($conversation === null || $conversation->getAttribute('handler') !== 'bot' || $conversation->getAttribute('bot_session_id') !== $this->session->id) {
                    throw new BotPreempted;
                }
                if (! in_array(ChatbotSession::query()->whereKey($this->session->id)->value('status'), ChatbotSession::RUNNING, true)) {
                    throw new BotPreempted;
                }
                app(SendMessage::class)->toConversation($conversation, $data, MessageOrigin::Automation, null, $key);
            });
        } catch (MessagingException $e) {
            $reason = match ($e->errorCode()) {
                ErrorCode::WindowClosed => 'window_closed',
                ErrorCode::ContactOptedOut => 'opted_out',
                ErrorCode::NumberUnavailable => 'number_unavailable',
                ErrorCode::TemplateUnavailable => 'template_unavailable',
                default => 'send_failed',
            };
            throw new BotSendFailed($e->getMessage(), $reason);
        }
    }

    public function updateContact(array $changes): void
    {
        $data = array_intersect_key($changes, ['name' => true, 'email' => true, 'tags' => true]);
        if (isset($changes['fields'])) {
            $data['attributes'] = array_merge((array) ($this->contactModel->custom_fields ?? []), $changes['fields']);
        }
        $this->contactModel = app(ManageContacts::class)->update($this->contactModel, $data, partial: true);
        $this->contact = self::snapshot($this->contactModel);
    }

    public function assign(string $mode, ?string $memberId, string $reason): ?string
    {
        try {
            $conversation = Conversation::query()->with('contact')->findOrFail($this->conversation->id);
            $tools = app(InboxTools::class);
            $member = null;

            if ($mode === 'route') {
                // Handoff without a choice: the workspace's own routing rule (round robin, or nobody).
                if ($conversation->assigned_membership_id === null) {
                    $tools->route($conversation);
                    $conversation->refresh();
                }
                $member = $conversation->assigned_membership_id !== null ? TenantMembership::query()->find($conversation->assigned_membership_id) : null;
                if ($member !== null) {
                    $tools->notify($member, 'assigned', $reason, $conversation->contact?->displayName(), "/inbox?c={$conversation->id}");
                }

                return $member?->user()->value('name');
            }

            if ($mode === 'member' && $memberId !== null) {
                $member = TenantMembership::query()->active()->find($memberId);
            } elseif ($mode === 'auto') {
                $member = $conversation->assigned_membership_id !== null
                    ? TenantMembership::query()->find($conversation->assigned_membership_id)
                    : $tools->pickAgent($conversation);
            }
            if ($member === null) {
                return null;
            }
            $conversation->forceFill(['assigned_membership_id' => $member->id])->save();
            $tools->notify($member, 'assigned', $reason, $conversation->contact?->displayName(), "/inbox?c={$conversation->id}", email: true);

            return $member->user()->value('name') ?? 'a teammate';
        } catch (Throwable $e) {
            Log::warning('Chatbot assignment failed', ['conversation' => $this->conversation->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function closeConversation(): void
    {
        Conversation::query()->whereKey($this->conversation->id)->update([
            'status' => ConversationStatus::Closed->value, 'closed_at' => now(), 'handler' => null, 'handler_changed_at' => now(),
        ]);
    }

    public function inSegment(string $segment): bool
    {
        $found = Segment::query()->where(fn ($q) => $q->where('name', 'ilike', $segment)
            ->when(preg_match('/^[0-9a-f-]{36}$/i', $segment) === 1, fn ($w) => $w->orWhere('id', $segment)))->first();
        if ($found === null) {
            return false;
        }

        return app(SegmentQuery::class)->apply(Contact::query()->whereKey($this->contactModel->id), $found->match, (array) $found->rules)->exists();
    }

    public function businessOpen(): bool
    {
        return InboxTools::isOpen(app(TenantContext::class)->tenantOrNull());
    }

    public function secret(string $name): ?string
    {
        $row = ChatbotSecret::query()->where('name', $name)->first();
        try {
            return $row !== null ? app(SecretStore::class)->get($row->secret_id) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function event(string $nodeId, string $kind, ?string $detail = null): void
    {
        ChatbotEvent::query()->create([
            'flow_id' => $this->session->flow_id, 'flow_version' => $this->session->flow_version, 'session_id' => $this->session->id,
            'node_id' => mb_substr($nodeId, 0, 64), 'kind' => $kind, 'detail' => $detail !== null ? mb_substr($detail, 0, 120) : null,
        ]);
    }

    /**
     * Saves the session and the conversation's bot state. A session a person took over in the
     * meantime keeps the status the takeover gave it: only the step counter is saved then.
     */
    public function persist(?Carbon $inboundAt = null): void
    {
        DB::transaction(function () use ($inboundAt): void {
            /** @var ?Conversation $conversation */
            $conversation = Conversation::query()->whereKey($this->conversation->id)->lockForUpdate()->first();
            /** @var ChatbotSession $session */
            $session = ChatbotSession::query()->whereKey($this->session->id)->lockForUpdate()->firstOrFail();

            if (! $session->isRunning() || ($this->state['preempted'] ?? false)) {
                $session->forceFill(['steps' => max($session->steps, $this->state['steps'])])->save();

                return;
            }

            $ended = ! in_array($this->state['status'], ChatbotSession::RUNNING, true);
            $session->forceFill([
                'status' => $this->state['status'],
                'current_node' => $this->state['current_node'],
                'awaiting_input' => $this->state['awaiting_input'],
                'retry_count' => $this->state['retry_count'],
                'vars' => $this->state['vars'] ?: null,
                'steps' => $this->state['steps'],
                'resume_at' => $this->state['resume_at'],
                'last_activity_at' => now(),
                'reminder_sent_at' => $inboundAt !== null ? null : $session->reminder_sent_at,
                'last_inbound_at' => $inboundAt !== null && ($session->last_inbound_at === null || $inboundAt->gt($session->last_inbound_at)) ? $inboundAt : $session->last_inbound_at,
                'ended_reason' => $ended ? $this->state['ended_reason'] : null,
                'ended_node' => $ended ? $this->state['ended_node'] : null,
                'ended_at' => $ended ? now() : null,
            ])->save();

            if ($conversation !== null && $conversation->getAttribute('bot_session_id') === $session->id) {
                $updates = ['bot_state' => $ended ? $this->state['status'] : 'running'];
                if ($ended && $conversation->getAttribute('handler') === 'bot') {
                    // Handoff: a person answers from now on. End / stop: nobody until the customer writes.
                    $updates += ['handler' => $this->state['status'] === 'handed_off' ? 'human' : null, 'handler_changed_at' => now()];
                }
                $conversation->forceFill($updates)->save();
            }
        });
    }
}
