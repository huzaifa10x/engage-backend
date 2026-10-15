<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Services;

use App\Application\Messaging\ManageContacts;
use App\Domain\Chatbots\Engine\BotInput;
use App\Domain\Chatbots\Engine\FlowEngine;
use App\Domain\Chatbots\Engine\FlowGraph;
use App\Domain\Chatbots\Engine\FlowSettings;
use App\Domain\Chatbots\Engine\LiveRuntime;
use App\Domain\Chatbots\Models\ChatbotEvent;
use App\Domain\Chatbots\Models\ChatbotFlow;
use App\Domain\Chatbots\Models\ChatbotFlowVersion;
use App\Domain\Chatbots\Models\ChatbotSession;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\ConsentService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs chatbots on top of the inbox. The inbound message is stored and visible in the inbox
 * before any of this happens (the bot runs in its own queued job); the bot never hides,
 * holds back or replaces a conversation.
 *
 * Who answers is the conversation's `handler`:
 *   bot   — a session is running; agents watch and can take over at any moment
 *   human — an agent (or the WhatsApp Business app) replied, someone took over, or the flow
 *           handed off; the bot stays silent until "Hand back to bot", the auto-resume time,
 *           or the conversation is closed
 *   null  — nobody yet; the next customer message may start the number's live flow
 */
final class Chatbots
{
    /** @var array<string, array{0: FlowGraph, 1: array<string, mixed>}|null> */
    private array $versions = [];

    public function __construct(private readonly FlowEngine $engine, private readonly ConsentService $consent) {}

    /** Will a bot look at this customer message? (Auto-reply and auto-routing then stay out of its way.) */
    public function wantsMessage(Conversation $conversation): bool
    {
        if ($conversation->getAttribute('handler') === 'human') {
            return false;
        }

        return ChatbotSession::query()->where('conversation_id', $conversation->id)->whereIn('status', ChatbotSession::RUNNING)->exists()
            || ChatbotFlow::liveFor($conversation->phone_number_id) !== null;
    }

    /** Is any bot set up for this number or running in this conversation (handler aside)? */
    public function presentFor(Conversation $conversation): bool
    {
        return ChatbotFlow::liveFor($conversation->phone_number_id) !== null
            || ChatbotSession::query()->where('conversation_id', $conversation->id)->whereIn('status', ChatbotSession::RUNNING)->exists();
    }

    /** Entry point of the RunChatbot job: one customer message, one conversation at a time. */
    public function onInbound(string $messageId): void
    {
        $message = Message::query()->find($messageId);
        if ($message === null || $message->direction !== Message::INBOUND || $message->origin !== MessageOrigin::Customer) {
            return;
        }

        Cache::lock("chatbot:conversation:{$message->conversation_id}", 120)->block(60, function () use ($message): void {
            $this->handle($message);
        });
    }

    private function handle(Message $message): void
    {
        $conversation = Conversation::query()->find($message->conversation_id);
        /** @var ?Contact $contact */
        $contact = $conversation?->contact()->first();
        if ($conversation === null || $contact === null || $contact->isOptedOut()) {
            return;
        }
        // STOP / START get their own handling and confirmation; the bot never answers them.
        if (in_array($message->type, ['text', 'button', 'interactive'], true) && $this->consent->keywordIntent((string) $message->body) !== null) {
            return;
        }
        if (in_array($message->type, ['reaction', 'system', 'unsupported'], true)) {
            return;
        }

        $at = $message->meta_timestamp ?? $message->created_at ?? now();
        $input = BotInput::fromMessage($message);
        $flow = ChatbotFlow::liveFor($conversation->phone_number_id);

        if ($conversation->getAttribute('handler') === 'human') {
            if (! $this->autoResumeDue($conversation, $flow)) {
                return;
            }
            $conversation->forceFill(['handler' => null, 'handler_changed_at' => now(), 'bot_state' => null])->save();
        }

        $session = ChatbotSession::query()->where('conversation_id', $conversation->id)->whereIn('status', ChatbotSession::RUNNING)->first();
        if ($session !== null) {
            $this->continueSession($session, $conversation, $contact, $flow, $input, $at);

            return;
        }
        if ($flow === null || $flow->published_version === null) {
            return;
        }
        [$graph, $settings] = $this->version($flow->id, $flow->published_version) ?? [null, null];
        if ($graph === null) {
            return;
        }

        // Back soon after leaving: continue where they left off (their message may be the answer).
        $previous = ChatbotSession::query()->where('conversation_id', $conversation->id)->where('flow_id', $flow->id)->latest('created_at')->first();
        if ($previous !== null && $previous->status === 'abandoned' && $previous->flow_version === $flow->published_version
            && $previous->ended_at !== null && $previous->ended_at->gte(now()->subMinutes($settings['resume_minutes']))
            && in_array($graph->node($previous->ended_node)['type'] ?? null, FlowGraph::INPUT, true)) {
            $resumed = $this->claim($conversation, fn () => tap($previous)->forceFill([
                'status' => 'active', 'current_node' => $previous->ended_node, 'awaiting_input' => true, 'retry_count' => 0,
                'ended_reason' => null, 'ended_node' => null, 'ended_at' => null, 'reminder_sent_at' => null, 'last_activity_at' => now(),
            ])->save());
            if ($resumed !== null) {
                $rt = new LiveRuntime($resumed, $conversation, $contact);
                $rt->event((string) $resumed->current_node, 'enter', 'resumed');
                $this->engine->inbound($graph, $settings, $rt, $input);
                $rt->persist(Carbon::instance($at));
            }

            return;
        }

        if (! $this->triggers($graph, $input)) {
            return;
        }
        $session = $this->claim($conversation, fn () => ChatbotSession::query()->create([
            'flow_id' => $flow->id, 'flow_version' => $flow->published_version, 'conversation_id' => $conversation->id,
            'contact_id' => $contact->id, 'phone_number_id' => $conversation->phone_number_id, 'status' => 'active',
            'last_inbound_at' => $at, 'last_activity_at' => now(),
            'vars' => ['last_input' => $input->text !== null ? mb_substr($input->text, 0, 1000) : null, 'started_from_ad' => $input->fromAd ?: null],
        ]));
        if ($session === null) {
            return;
        }
        $rt = new LiveRuntime($session, $conversation, $contact);
        $this->engine->begin($graph, $settings, $rt);
        $rt->persist(Carbon::instance($at));
    }

    private function continueSession(ChatbotSession $session, Conversation $conversation, Contact $contact, ?ChatbotFlow $live, BotInput $input, mixed $at): void
    {
        if ($session->last_inbound_at !== null && $at->lt($session->last_inbound_at)) {
            return; // an older message delivered late: its turn has passed
        }
        // The flow was switched off or replaced on this number since the session began.
        if ($live === null || $live->id !== $session->flow_id) {
            $this->end($session, 'stopped', 'flow_disabled');

            return;
        }
        $version = $this->version($session->flow_id, $session->flow_version);
        if ($version === null) {
            $this->end($session, 'stopped', 'version_missing');

            return;
        }
        if ($conversation->getAttribute('handler') !== 'bot' || $conversation->getAttribute('bot_session_id') !== $session->id) {
            $conversation->forceFill(['handler' => 'bot', 'bot_session_id' => $session->id, 'bot_state' => 'running', 'handler_changed_at' => now()])->save();
        }
        if ($session->status === 'waiting') {
            $session->forceFill(['last_inbound_at' => $at])->save(); // a Delay is running: it continues on its own time

            return;
        }

        $rt = new LiveRuntime($session, $conversation, $contact);
        $this->engine->inbound($version[0], $version[1], $rt, $input);
        $rt->persist(Carbon::instance($at));
    }

    /**
     * Makes the bot the conversation's handler and creates (or revives) its session in the same
     * transaction, under the conversation's row lock. Null when a person got there first.
     *
     * @param  callable(): (ChatbotSession|bool|null)  $session
     */
    private function claim(Conversation $conversation, callable $session): ?ChatbotSession
    {
        try {
            return DB::transaction(function () use ($conversation, $session): ?ChatbotSession {
                $locked = Conversation::query()->whereKey($conversation->id)->lockForUpdate()->first();
                if ($locked === null || $locked->getAttribute('handler') === 'human') {
                    return null;
                }
                $result = $session();
                $model = $result instanceof ChatbotSession ? $result : ChatbotSession::query()->where('conversation_id', $conversation->id)->whereIn('status', ChatbotSession::RUNNING)->first();
                if ($model === null) {
                    return null;
                }
                $locked->forceFill(['handler' => 'bot', 'handler_changed_at' => now(), 'bot_session_id' => $model->id, 'bot_state' => 'running'])->save();
                $conversation->setRawAttributes($locked->getAttributes(), true);

                return $model;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return null; // another worker started a session for this conversation a moment ago
            }
            throw $e;
        }
    }

    private function triggers(FlowGraph $graph, BotInput $input): bool
    {
        $c = $graph->start()['config'] ?? null;
        if ($c === null) {
            return false;
        }
        if ($c['any_message'] || ($c['ad_referral'] && $input->fromAd)) {
            return true;
        }
        $words = $input->normalized();
        foreach ($c['keywords'] as $keyword) {
            if ($words === $keyword || str_starts_with($words, $keyword.' ')) {
                return true;
            }
        }

        return false;
    }

    private function autoResumeDue(Conversation $conversation, ?ChatbotFlow $flow): bool
    {
        if ($flow === null || $flow->published_version === null) {
            return false;
        }
        $minutes = $this->version($flow->id, $flow->published_version)[1]['auto_resume_minutes'] ?? null;
        $since = $conversation->getAttribute('handler_changed_at');

        return $minutes !== null && $since !== null && Carbon::parse($since)->lte(now()->subMinutes((int) $minutes));
    }

    // ── Actions from the inbox ───────────────────────────────────────────────────────────────

    /** An agent clicked "Take over": the bot stops talking in this conversation right away. */
    public function takeOver(Conversation $conversation): void
    {
        DB::transaction(function () use ($conversation): void {
            $locked = Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $this->pauseRunning($locked, 'taken_over');
            $locked->forceFill(['handler' => 'human', 'handler_changed_at' => now()])->save();
        });
    }

    /**
     * "Hand back to bot": the person is done. With $restart (and an open 24-hour window) the
     * number's flow starts again from the top right away; otherwise the next customer message does.
     */
    public function handBack(Conversation $conversation, bool $restart = true): bool
    {
        Cache::lock("chatbot:conversation:{$conversation->id}", 120)->block(30, function () use ($conversation, &$started, $restart): void {
            DB::transaction(function () use ($conversation): void {
                $locked = Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
                $this->pauseRunning($locked, 'handed_back');
                $locked->forceFill(['handler' => null, 'handler_changed_at' => now(), 'bot_state' => null])->save();
            });
            $conversation->refresh();
            $started = $restart && $conversation->isWindowOpen() && $this->startNow($conversation);
        });

        return (bool) ($started ?? false);
    }

    private function startNow(Conversation $conversation): bool
    {
        $flow = ChatbotFlow::liveFor($conversation->phone_number_id);
        /** @var ?Contact $contact */
        $contact = $conversation->contact()->first();
        if ($flow === null || $contact === null || $contact->isOptedOut() || ($version = $this->version($flow->id, (int) $flow->published_version)) === null) {
            return false;
        }
        $session = $this->claim($conversation, fn () => ChatbotSession::query()->create([
            'flow_id' => $flow->id, 'flow_version' => $flow->published_version, 'conversation_id' => $conversation->id,
            'contact_id' => $contact->id, 'phone_number_id' => $conversation->phone_number_id, 'status' => 'active', 'last_activity_at' => now(),
        ]));
        if ($session === null) {
            return false;
        }
        $rt = new LiveRuntime($session, $conversation, $contact);
        $this->engine->begin($version[0], $version[1], $rt);
        $rt->persist();

        return true;
    }

    /** Closing a conversation, an opt-out, a flow switched off: whatever runs stops, nothing is sent. */
    public function stop(Conversation $conversation, string $reason): void
    {
        DB::transaction(function () use ($conversation, $reason): void {
            $locked = Conversation::query()->whereKey($conversation->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }
            $session = ChatbotSession::query()->where('conversation_id', $locked->id)->whereIn('status', ChatbotSession::RUNNING)->first();
            if ($session !== null) {
                $this->mark($session, 'stopped', $reason);
                $locked->forceFill(['bot_state' => 'stopped'])->save();
            }
            if ($reason === 'closed' || $locked->getAttribute('handler') === 'bot') {
                $locked->forceFill(['handler' => null, 'handler_changed_at' => now()])->save();
            }
        });
    }

    /** Every running session of a flow (switched off, replaced or deleted). */
    public function stopFlow(ChatbotFlow $flow, string $reason): void
    {
        ChatbotSession::query()->where('flow_id', $flow->id)->whereIn('status', ChatbotSession::RUNNING)->get()
            ->each(fn (ChatbotSession $s) => $this->end($s, 'stopped', $reason));
    }

    /**
     * Inside ConversationTracker::apply (conversation row already locked): an agent, an API client
     * or the WhatsApp Business app sent a message, so a person is handling this conversation.
     *
     * @return array<string, mixed> column updates for the conversation
     */
    public function humanReplied(Conversation $locked): array
    {
        $updates = ['handler' => 'human', 'handler_changed_at' => now()];
        if ($locked->getAttribute('handler') === 'bot' && $locked->getAttribute('bot_session_id') !== null) {
            $session = ChatbotSession::query()->whereKey($locked->getAttribute('bot_session_id'))->whereIn('status', ChatbotSession::RUNNING)->first();
            if ($session !== null) {
                $this->mark($session, 'handed_off', 'agent_replied');
                $updates['bot_state'] = 'handed_off';
            }
        }

        return $updates;
    }

    // ── Timers (engage:chatbots:tick, every minute) ──────────────────────────────────────────

    /** Step reminder, abandonment and Delay continuation for one session (tenant context set). */
    public function tick(ChatbotSession $session): ?string
    {
        return Cache::lock("chatbot:conversation:{$session->conversation_id}", 120)->block(30, function () use ($session): ?string {
            $session->refresh();
            if (! $session->isRunning()) {
                return null;
            }
            $version = $this->version($session->flow_id, $session->flow_version);
            $conversation = Conversation::query()->find($session->conversation_id);
            /** @var ?Contact $contact */
            $contact = $conversation?->contact()->first();
            if ($version === null || $conversation === null || $contact === null) {
                $this->end($session, 'stopped', 'missing');

                return 'stopped';
            }
            [$graph, $settings] = $version;
            $idle = $session->last_activity_at ?? $session->created_at ?? now();

            if ($session->status === 'waiting') {
                if ($session->resume_at === null || $session->resume_at->isFuture()) {
                    return null;
                }
                $rt = new LiveRuntime($session, $conversation, $contact);
                $this->engine->resume($graph, $settings, $rt);
                $rt->persist();

                return 'resumed';
            }

            if ($idle->lte(now()->subMinutes($settings['abandon_minutes']))) {
                $this->abandon($session, $settings, $contact);

                return 'abandoned';
            }
            $reminder = $settings['reminder_minutes'];
            if ($reminder !== null && $session->awaiting_input && $session->reminder_sent_at === null && $idle->lte(now()->subMinutes($reminder)) && $conversation->isWindowOpen()) {
                $session->forceFill(['reminder_sent_at' => now()])->save();
                $rt = new LiveRuntime($session, $conversation, $contact);
                $this->engine->remind($settings, $rt);
                $session->refresh();
                if ($session->isRunning() && ! ($rt->state['preempted'] ?? false)) {
                    $session->forceFill(['steps' => $rt->state['steps']] + (in_array($rt->state['status'], ChatbotSession::RUNNING, true) ? [] : [
                        'status' => $rt->state['status'], 'ended_reason' => $rt->state['ended_reason'], 'ended_at' => now(),
                    ]))->save();
                }

                return 'reminded';
            }

            return null;
        });
    }

    /**
     * The customer stopped answering: the bot gives up, the conversation stays open in the inbox.
     *
     * @param  array<string, mixed>  $settings
     */
    private function abandon(ChatbotSession $session, array $settings, Contact $contact): void
    {
        $this->end($session, 'abandoned', 'inactive');
        if ($settings['abandoned_tag'] !== null) {
            try {
                $tags = array_values(array_unique([...(array) ($contact->tags ?? []), $settings['abandoned_tag']]));
                app(ManageContacts::class)->update($contact, ['tags' => $tags], partial: true);
            } catch (Throwable $e) {
                Log::info('Abandoned-flow tag not added', ['contact' => $contact->id, 'error' => $e->getMessage()]);
            }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────

    /** Ends a session and releases the conversation (abandoned / stopped / completed). */
    private function end(ChatbotSession $session, string $status, string $reason): void
    {
        DB::transaction(function () use ($session, $status, $reason): void {
            $conversation = Conversation::query()->whereKey($session->conversation_id)->lockForUpdate()->first();
            $fresh = ChatbotSession::query()->whereKey($session->id)->lockForUpdate()->first();
            if ($fresh === null || ! $fresh->isRunning()) {
                return;
            }
            $this->mark($fresh, $status, $reason);
            if ($conversation !== null && $conversation->getAttribute('bot_session_id') === $fresh->id) {
                $updates = ['bot_state' => $status];
                if ($conversation->getAttribute('handler') === 'bot') {
                    $updates += ['handler' => null, 'handler_changed_at' => now()];
                }
                $conversation->forceFill($updates)->save();
            }
        });
    }

    private function mark(ChatbotSession $session, string $status, string $reason): void
    {
        $session->forceFill([
            'status' => $status, 'awaiting_input' => false, 'ended_reason' => $reason, 'ended_node' => $session->current_node, 'ended_at' => now(),
        ])->save();
        $kind = match ($status) {
            'abandoned' => 'abandon',
            'handed_off' => 'handoff',
            'completed' => 'complete',
            default => 'error',
        };
        ChatbotEvent::query()->create([
            'flow_id' => $session->flow_id, 'flow_version' => $session->flow_version, 'session_id' => $session->id,
            'node_id' => (string) ($session->current_node ?? 'start'), 'kind' => $kind, 'detail' => $reason,
        ]);
    }

    private function pauseRunning(Conversation $locked, string $reason): void
    {
        $session = ChatbotSession::query()->where('conversation_id', $locked->id)->whereIn('status', ChatbotSession::RUNNING)->first();
        if ($session !== null) {
            $this->mark($session, 'handed_off', $reason);
            $locked->forceFill(['bot_state' => 'handed_off']);
        }
    }

    /** @return array{0: FlowGraph, 1: array<string, mixed>}|null */
    public function version(string $flowId, int $version): ?array
    {
        $key = "{$flowId}:{$version}";
        if (! array_key_exists($key, $this->versions)) {
            $row = ChatbotFlowVersion::query()->where('flow_id', $flowId)->where('version', $version)->first();
            $this->versions[$key] = $row !== null ? [FlowGraph::from($row->graph), FlowSettings::normalize($row->settings)] : null;
        }

        return $this->versions[$key];
    }
}
