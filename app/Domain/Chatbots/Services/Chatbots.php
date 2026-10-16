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
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\ConsentService;
use App\Domain\Messaging\Services\ConversationTracker;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Models\PhoneNumber;
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
 *           handed off; the bot stays silent until one of:
 *             • an agent clicks "Hand back to bot" (restarts now when a reply is allowed),
 *             • the conversation is closed (the next customer message may start a flow),
 *             • the flow's "auto-resume" time has passed since the last agent message or the
 *               handover, and the customer writes again (off unless set in Flow settings).
 *           The bot never takes a conversation back by sending something on its own.
 *   null  — nobody yet; the next customer message may start one of the number's live flows
 *
 * Which flow answers a customer message (entry precedence — the first that applies wins):
 *   1. a person handles the conversation → no bot
 *   2. STOP / START and other consent keywords → the consent service answers, never a flow
 *      (a customer who opted out and writes again may be answered inside the window they opened:
 *      SendMessage allows those replies and refuses templates; nothing is started by the business)
 *   3. a session is running → it continues
 *   4. a flow whose specific trigger matches: campaign button reply, QR code, ad, keyword / pattern
 *   5. the greeting flow, for a customer writing for the first time
 *   6. the default flow ("any message")
 * Only one flow answers (the primary responders are mutually exclusive); a flow skipped by its
 * re-entry rule lets the next one in line answer.
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
            // A person has it, unless the flow's auto-resume time has passed: then this message is the bot's.
            return $this->autoResumeDue($conversation);
        }

        return $this->presentFor($conversation);
    }

    /** Is any bot set up for this number or running in this conversation (handler aside)? */
    public function presentFor(Conversation $conversation): bool
    {
        return ChatbotFlow::anyLiveOn($conversation->phone_number_id)
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
        if ($conversation === null || $contact === null) {
            return;
        }
        // Opted out: the bot may only answer inside a window the customer opened by writing after
        // opting out (the same rule as an agent's reply). Consent is never changed or bypassed here.
        if ($contact->isOptedOut() && ! $conversation->acceptsFreeFormFrom($contact)) {
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

        if ($conversation->getAttribute('handler') === 'human') {
            if (! $this->autoResumeDue($conversation)) {
                return;
            }
            $conversation->forceFill(['handler' => null, 'handler_changed_at' => now(), 'bot_state' => null])->save();
        }

        $session = ChatbotSession::query()->where('conversation_id', $conversation->id)->whereIn('status', ChatbotSession::RUNNING)->first();
        if ($session !== null) {
            $this->continueSession($session, $conversation, $contact, $input, $at);

            return;
        }

        $picked = $this->pick($conversation, $contact, $input, $message);

        // Back soon after leaving: continue where they left off (their message may be the answer),
        // unless the message itself starts another flow with a specific trigger.
        if (($picked[2] ?? 'default') !== 'specific' && $this->resumeAbandoned($conversation, $contact, $input, $at)) {
            return;
        }
        if ($picked === null) {
            return;
        }
        [$flow, $version, $via] = $picked;
        $session = $this->claim($conversation, fn () => ChatbotSession::query()->create([
            'flow_id' => $flow->id, 'flow_version' => $flow->published_version, 'conversation_id' => $conversation->id,
            'contact_id' => $contact->id, 'phone_number_id' => $conversation->phone_number_id, 'status' => 'active',
            'last_inbound_at' => $at, 'last_activity_at' => now(),
            'vars' => array_filter(['last_input' => $input->text !== null ? mb_substr($input->text, 0, 1000) : null, 'started_from_ad' => $input->fromAd ?: null, '_via' => $via]),
        ]));
        if ($session === null) {
            return;
        }
        $rt = new LiveRuntime($session, $conversation, $contact);
        $this->engine->begin($version[0], $version[1], $rt, $input);
        $rt->persist(Carbon::instance($at));
    }

    /**
     * Picks the flow that answers a customer message (see the class comment for the order).
     *
     * @return ?array{0: ChatbotFlow, 1: array{0: FlowGraph, 1: array<string, mixed>}, 2: string}
     */
    public function pick(Conversation $conversation, Contact $contact, BotInput $input, ?Message $message = null): ?array
    {
        $flows = ChatbotFlow::liveOn($conversation->phone_number_id);
        if ($flows->isEmpty()) {
            return null;
        }
        $candidates = [];
        foreach ($flows as $flow) {
            $version = $this->version($flow->id, (int) $flow->published_version);
            $start = $version !== null ? $version[0]->start() : null;
            if ($start !== null) {
                $candidates[] = [$flow, $version, $start['config']];
            }
        }
        $words = $input->normalized();
        $text = mb_strtolower(trim((string) $input->text));
        $firstTime = null;

        $levels = [
            'campaign' => fn (array $c) => $c['campaign_buttons'] !== [] && in_array($input->type, ['button', 'interactive'], true)
                && ($words !== '' && in_array($words, array_map(fn ($b) => (new BotInput($b))->normalized(), $c['campaign_buttons']), true)),
            'qr' => fn (array $c) => $c['qr_code'] !== null && $text !== '' && str_contains($text, mb_strtolower($c['qr_code'])),
            'ad' => fn (array $c) => $input->fromAd && $c['ad_referral'] && ! $c['any_message'],
            'keyword' => fn (array $c) => $this->keywordMatch($c, $words, (string) $input->text),
            'greeting' => function (array $c) use ($contact, $message, &$firstTime): bool {
                if (! $c['greeting']) {
                    return false;
                }
                $firstTime ??= ! Message::query()->where('contact_id', $contact->id)->where('direction', Message::INBOUND)
                    ->when($message !== null, fn ($q) => $q->whereKeyNot($message->id))->exists();

                return $firstTime;
            },
            'default' => fn (array $c) => $c['any_message'] || ($input->fromAd && $c['ad_referral']),
        ];
        foreach ($levels as $level => $matches) {
            foreach ($candidates as [$flow, $version, $config]) {
                if ($matches($config) && $this->mayEnter($flow, $config, $contact)) {
                    return [$flow, $version, in_array($level, ['greeting', 'default'], true) ? $level : 'specific'];
                }
            }
        }

        return null;
    }

    /** @param array<string, mixed> $c the Start block's settings */
    private function keywordMatch(array $c, string $words, string $raw): bool
    {
        foreach ($c['keywords'] as $keyword) {
            $k = (new BotInput($keyword))->normalized();
            if ($k === '') {
                continue;
            }
            $hit = match ($c['keyword_match']) {
                'exact' => $words === $k,
                'contains' => preg_match('/(^|[^\p{L}\p{N}])'.preg_quote($k, '/').'($|[^\p{L}\p{N}])/u', $words) === 1,
                default => $words === $k || str_starts_with($words, $k.' '),
            };
            if ($hit) {
                return true;
            }
        }

        return $c['regex'] !== null && $raw !== '' && @preg_match('/'.str_replace('/', '\/', $c['regex']).'/iu', $raw) === 1;
    }

    /**
     * The flow's re-entry rule: always, only once per customer, or not again within N hours.
     *
     * @param  array<string, mixed>  $c
     */
    private function mayEnter(ChatbotFlow $flow, array $c, Contact $contact): bool
    {
        return match ($c['reentry']) {
            'once' => ! ChatbotSession::query()->where('flow_id', $flow->id)->where('contact_id', $contact->id)->exists(),
            'cooldown' => ! ChatbotSession::query()->where('flow_id', $flow->id)->where('contact_id', $contact->id)
                ->where('created_at', '>=', now()->subHours((int) $c['cooldown_hours']))->exists(),
            default => true,
        };
    }

    private function resumeAbandoned(Conversation $conversation, Contact $contact, BotInput $input, \DateTimeInterface $at): bool
    {
        $previous = ChatbotSession::query()->where('conversation_id', $conversation->id)->latest('created_at')->first();
        if ($previous === null || $previous->status !== 'abandoned' || $previous->ended_at === null) {
            return false;
        }
        $flow = ChatbotFlow::query()->find($previous->flow_id);
        $version = $flow !== null && $flow->is_active && $flow->published_version === $previous->flow_version ? $this->version($flow->id, $previous->flow_version) : null;
        if ($version === null || $previous->ended_at->lt(now()->subMinutes($version[1]['resume_minutes']))) {
            return false;
        }
        $vars = (array) ($previous->vars ?? []);
        $graph = isset($vars['_flow']) ? null : $version[0];
        if ($graph !== null && ! in_array($graph->node($previous->ended_node)['type'] ?? null, FlowGraph::INPUT, true)) {
            return false;
        }
        $resumed = $this->claim($conversation, fn () => tap($previous)->forceFill([
            'status' => 'active', 'current_node' => $previous->ended_node, 'awaiting_input' => true, 'retry_count' => 0,
            'ended_reason' => null, 'ended_node' => null, 'ended_at' => null, 'reminder_sent_at' => null, 'last_activity_at' => now(),
        ])->save());
        if ($resumed === null) {
            return true;
        }
        $rt = new LiveRuntime($resumed, $conversation, $contact);
        $rt->event((string) $resumed->current_node, 'enter', 'resumed');
        $this->engine->inbound($version[0], $version[1], $rt, $input);
        $rt->persist(Carbon::instance($at));

        return true;
    }

    private function continueSession(ChatbotSession $session, Conversation $conversation, Contact $contact, BotInput $input, \DateTimeInterface $at): void
    {
        if ($session->last_inbound_at !== null && Carbon::instance($at)->lt($session->last_inbound_at)) {
            return; // an older message delivered late: its turn has passed
        }
        // The flow was switched off or archived since the session began.
        if (! ChatbotFlow::query()->whereKey($session->flow_id)->where('is_active', true)->whereNull('archived_at')->exists()) {
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
            $session->forceFill(['last_inbound_at' => $at])->save(); // a Wait is running: it continues on its own time

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

    private function autoResumeDue(Conversation $conversation): bool
    {
        $at = $this->autoResumeAt($conversation);

        return $at !== null && ! $at->isFuture();
    }

    /**
     * While a person handles the conversation: from when the bot takes it back at the customer's
     * next message (the flow's auto-resume time after the last agent message or the handover).
     * Null = only "Hand back to bot" or closing the conversation returns it.
     */
    public function autoResumeAt(Conversation $conversation): ?Carbon
    {
        if ($conversation->getAttribute('handler') !== 'human') {
            return null;
        }
        $session = $conversation->getAttribute('bot_session_id') !== null ? ChatbotSession::query()->find($conversation->getAttribute('bot_session_id')) : null;
        $flow = $session !== null ? ChatbotFlow::query()->whereKey($session->flow_id)->where('is_active', true)->first() : null;
        $flow ??= $this->defaultFlow($conversation->phone_number_id);
        if ($flow === null || $flow->published_version === null) {
            return null;
        }
        $minutes = $this->version($flow->id, $flow->published_version)[1]['auto_resume_minutes'] ?? null;
        $since = $conversation->getAttribute('handler_changed_at');

        return $minutes !== null && $since !== null ? Carbon::parse($since)->addMinutes((int) $minutes) : null;
    }

    /** The number's default flow ("any message"), if one is live. */
    private function defaultFlow(string $phoneNumberId): ?ChatbotFlow
    {
        foreach (ChatbotFlow::liveOn($phoneNumberId) as $flow) {
            if (($this->version($flow->id, (int) $flow->published_version)[0] ?? null)?->start()['config']['any_message'] ?? false) {
                return $flow;
            }
        }

        return null;
    }

    // ── Starting a flow without a customer message (data change, incoming webhook) ───────────

    /**
     * Starts a flow for a contact on the flow's number. Skipped when the contact opted out, a
     * person handles the conversation, a session is already running, or the re-entry rule says no.
     *
     * @param  array<string, mixed>  $vars  values the flow can use as {{var.…}}
     * @return string started | skipped:<why>
     */
    public function startFor(ChatbotFlow $flow, Contact $contact, array $vars, string $via): string
    {
        if (! $flow->is_active || $flow->published_version === null || $flow->phone_number_id === null || $flow->kind !== 'bot') {
            return 'skipped:not_live';
        }
        if ($contact->isOptedOut()) {
            return 'skipped:opted_out';
        }
        $number = PhoneNumber::query()->find($flow->phone_number_id);
        $version = $this->version($flow->id, $flow->published_version);
        if ($number === null || $number->status !== PhoneNumberStatus::Connected || $version === null) {
            return 'skipped:number_unavailable';
        }
        $start = $version[0]->start();
        if ($start === null || ! $this->mayEnter($flow, $start['config'], $contact)) {
            return 'skipped:reentry';
        }
        $conversation = app(ConversationTracker::class)->forContact($number, $contact);

        return Cache::lock("chatbot:conversation:{$conversation->id}", 120)->block(30, function () use ($flow, $contact, $conversation, $vars, $via, $version): string {
            $conversation->refresh();
            if ($conversation->getAttribute('handler') === 'human') {
                return 'skipped:human';
            }
            if (ChatbotSession::query()->where('conversation_id', $conversation->id)->whereIn('status', ChatbotSession::RUNNING)->exists()) {
                return 'skipped:busy';
            }
            $session = $this->claim($conversation, fn () => ChatbotSession::query()->create([
                'flow_id' => $flow->id, 'flow_version' => $flow->published_version, 'conversation_id' => $conversation->id,
                'contact_id' => $contact->id, 'phone_number_id' => $conversation->phone_number_id, 'status' => 'active', 'last_activity_at' => now(),
                'vars' => array_slice($vars, 0, 40, true) + ['_via' => $via],
            ]));
            if ($session === null) {
                return 'skipped:human';
            }
            $rt = new LiveRuntime($session, $conversation, $contact);
            $this->engine->begin($version[0], $version[1], $rt);
            $rt->persist();

            return 'started';
        });
    }

    /**
     * A contact got new tags or changed fields: start the live flows that wait for that.
     *
     * @param  list<string>  $tagsAdded
     * @param  list<string>  $fieldsChanged
     */
    public function contactChanged(Contact $contact, array $tagsAdded, array $fieldsChanged): void
    {
        if ($tagsAdded === [] && $fieldsChanged === []) {
            return;
        }
        $added = array_map('mb_strtolower', $tagsAdded);
        $flows = ChatbotFlow::query()->where('is_active', true)->where('kind', 'bot')->whereNotNull('published_version')->whereNull('archived_at')->whereNotNull('phone_number_id')->get();
        foreach ($flows as $flow) {
            $c = ($this->version($flow->id, (int) $flow->published_version)[0] ?? null)?->start()['config'] ?? null;
            if ($c === null) {
                continue;
            }
            $tag = array_values(array_intersect(array_map('mb_strtolower', $c['tags_added']), $added));
            $field = array_values(array_intersect($c['fields_changed'], $fieldsChanged));
            if ($tag === [] && $field === []) {
                continue;
            }
            try {
                $this->startFor($flow, $contact, array_filter(['trigger_tag' => $tag[0] ?? null, 'trigger_field' => $field[0] ?? null]), $tag !== [] ? 'tag' : 'field');
            } catch (Throwable $e) {
                Log::warning('Chatbot data-change start failed', ['flow' => $flow->id, 'contact' => $contact->id, 'error' => $e->getMessage()]);
            }
        }
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
     * "Hand back to bot": the person is done. With $restart (and an open 24-hour window) the flow
     * that ran last (or the number's default flow) starts again from the top right away; otherwise
     * the next customer message decides.
     */
    public function handBack(Conversation $conversation, bool $restart = true): bool
    {
        return $this->handBackOutcome($conversation, $restart) === 'started';
    }

    /**
     * "Hand back to bot", saying what happened: started (the flow runs now), waiting (the next
     * customer message decides), window_closed (no reply allowed until the customer writes),
     * opted_out (they opted out and have not written since: only their next message reopens it).
     */
    public function handBackOutcome(Conversation $conversation, bool $restart = true): string
    {
        $outcome = 'waiting';
        Cache::lock("chatbot:conversation:{$conversation->id}", 120)->block(30, function () use ($conversation, &$outcome, $restart): void {
            $last = $conversation->getAttribute('bot_session_id');
            DB::transaction(function () use ($conversation): void {
                $locked = Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
                $this->pauseRunning($locked, 'handed_back');
                $locked->forceFill(['handler' => null, 'handler_changed_at' => now(), 'bot_state' => null])->save();
            });
            $conversation->refresh();
            /** @var ?Contact $contact */
            $contact = $conversation->contact()->first();
            $outcome = match (true) {
                ! $restart => 'waiting',
                ! $conversation->isWindowOpen() => 'window_closed',
                $contact !== null && ! $conversation->acceptsFreeFormFrom($contact) => 'opted_out',
                $this->startNow($conversation, is_string($last) ? $last : null) => 'started',
                default => 'waiting',
            };
        });

        return $outcome;
    }

    private function startNow(Conversation $conversation, ?string $lastSessionId): bool
    {
        $lastFlow = $lastSessionId !== null ? ChatbotSession::query()->whereKey($lastSessionId)->value('flow_id') : null;
        $flow = $lastFlow !== null ? ChatbotFlow::liveOn($conversation->phone_number_id)->firstWhere('id', $lastFlow) : null;
        $flow ??= $this->defaultFlow($conversation->phone_number_id);
        /** @var ?Contact $contact */
        $contact = $conversation->contact()->first();
        // Only where a reply is allowed: an opted-out customer must have written after opting out.
        if ($flow === null || $contact === null || ! $conversation->acceptsFreeFormFrom($contact) || ($version = $this->version($flow->id, (int) $flow->published_version)) === null) {
            return false;
        }
        $session = $this->claim($conversation, fn () => ChatbotSession::query()->create([
            'flow_id' => $flow->id, 'flow_version' => $flow->published_version, 'conversation_id' => $conversation->id,
            'contact_id' => $contact->id, 'phone_number_id' => $conversation->phone_number_id, 'status' => 'active', 'last_activity_at' => now(),
            'vars' => ['_via' => 'handed_back'],
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

    /** Waits, delivery reports, "No reply" timers, the step reminder and abandonment for one session (tenant context set). */
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
                $delivery = $session->vars['_delivery'] ?? null;
                if (is_string($delivery) && ($output = $this->deliveryOutcome($delivery)) !== null) {
                    $rt = new LiveRuntime($session, $conversation, $contact);
                    $this->engine->resume($graph, $settings, $rt, $output);
                    $rt->persist();

                    return $output === 'failed' ? 'not_delivered' : 'resumed';
                }
                if ($session->resume_at === null || $session->resume_at->isFuture()) {
                    return null;
                }
                $rt = new LiveRuntime($session, $conversation, $contact);
                $this->engine->resume($graph, $settings, $rt);
                $rt->persist();

                return 'resumed';
            }

            if ($session->timeout_at !== null) {
                if ($session->timeout_at->isPast()) {
                    $rt = new LiveRuntime($session, $conversation, $contact);
                    $this->engine->timeout($graph, $settings, $rt);
                    $rt->persist();

                    return 'timed_out';
                }

                // The step has its own "No reply" timer: the flow-wide abandonment waits for it.
                return null;
            }

            if ($idle->lte(now()->subMinutes($settings['abandon_minutes']))) {
                $this->abandon($session, $settings, $contact);

                return 'abandoned';
            }
            $reminder = $settings['reminder_minutes'];
            if ($reminder !== null && $session->awaiting_input && $session->reminder_sent_at === null && $idle->lte(now()->subMinutes($reminder)) && $conversation->isWindowOpen()) {
                $session->forceFill(['reminder_sent_at' => now()])->save();
                $rt = new LiveRuntime($session, $conversation, $contact);
                $this->engine->remind($settings, $rt, $graph);
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
     * A WhatsApp status report for a bot message: a template with a "Not delivered" branch that
     * waits for its report continues at once (StatusApplier → ChatbotDelivery job).
     */
    public function delivery(Message $message): void
    {
        $key = $message->getAttribute('idempotency_key');
        if (! is_string($key) || ! str_starts_with($key, 'bot:')) {
            return;
        }
        $session = ChatbotSession::query()->where('status', 'waiting')->where('vars->_delivery', $key)->first();
        if ($session !== null) {
            $this->tick($session);
        }
    }

    /** "failed", "next" (delivered or read), or null while WhatsApp has not said yet. */
    private function deliveryOutcome(string $key): ?string
    {
        $status = Message::query()->where('idempotency_key', $key)->first()?->status;

        return match ($status) {
            MessageStatus::Failed => 'failed',
            MessageStatus::Delivered, MessageStatus::Read => 'next',
            default => null,
        };
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

    /**
     * Scheduled switch-on / switch-off of flows (activate_at / deactivate_at), tenant context set.
     *
     * @return list<string> what happened, for the command output
     */
    public function schedules(): array
    {
        $done = [];
        foreach (ChatbotFlow::query()->whereNotNull('activate_at')->where('activate_at', '<=', now())->get() as $flow) {
            $problem = $this->activationProblem($flow);
            $flow->forceFill(['activate_at' => null] + ($problem === null ? ['is_active' => true] : []))->save();
            $done[] = $problem === null ? 'activated' : 'activation_skipped';
            if ($problem !== null) {
                Log::info('Scheduled chatbot activation skipped', ['flow' => $flow->id, 'reason' => $problem]);
            }
        }
        foreach (ChatbotFlow::query()->whereNotNull('deactivate_at')->where('deactivate_at', '<=', now())->get() as $flow) {
            $wasActive = $flow->is_active;
            $flow->forceFill(['deactivate_at' => null, 'is_active' => false])->save();
            if ($wasActive) {
                $this->stopFlow($flow, 'flow_disabled');
            }
            $done[] = 'deactivated';
        }

        return $done;
    }

    /**
     * Why a flow cannot go live right now (null = it can).
     */
    public function activationProblem(ChatbotFlow $flow, bool $ignoreConflict = false): ?string
    {
        if ($flow->kind !== 'bot') {
            return 'A subflow is never live by itself: switch on the flows that use it.';
        }
        if ($flow->archived_at !== null) {
            return 'Restore the flow from the archive first.';
        }
        if ($flow->published_version === null) {
            return 'Publish the flow before switching it on.';
        }
        if ($flow->phone_number_id === null) {
            return 'Choose the WhatsApp number this bot answers on.';
        }
        $number = PhoneNumber::query()->find($flow->phone_number_id);
        if ($number === null || $number->status !== PhoneNumberStatus::Connected) {
            return 'The WhatsApp number of this bot is not connected.';
        }
        $other = $ignoreConflict ? null : $this->conflict($flow);
        if ($other !== null) {
            return "“{$other[0]->name}” is already the {$other[1]} flow on this number.";
        }

        return null;
    }

    /**
     * Another live flow on the same number with the same catch-all role (default or greeting).
     *
     * @return ?array{0: ChatbotFlow, 1: string}
     */
    public function conflict(ChatbotFlow $flow): ?array
    {
        if ($flow->phone_number_id === null || $flow->published_version === null) {
            return null;
        }
        $mine = ($this->version($flow->id, $flow->published_version)[0] ?? null)?->start()['config'] ?? null;
        if ($mine === null || (! $mine['any_message'] && ! $mine['greeting'])) {
            return null;
        }
        foreach (ChatbotFlow::liveOn($flow->phone_number_id) as $other) {
            if ($other->id === $flow->id) {
                continue;
            }
            $theirs = ($this->version($other->id, (int) $other->published_version)[0] ?? null)?->start()['config'] ?? null;
            if ($theirs === null) {
                continue;
            }
            if ($mine['any_message'] && $theirs['any_message']) {
                return [$other, 'default (“any message”)'];
            }
            if ($mine['greeting'] && $theirs['greeting']) {
                return [$other, 'greeting'];
            }
        }

        return null;
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
            'status' => $status, 'awaiting_input' => false, 'timeout_at' => null, 'ended_reason' => $reason, 'ended_node' => $session->current_node, 'ended_at' => now(),
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
