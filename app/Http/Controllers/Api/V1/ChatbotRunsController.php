<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Permission;
use App\Domain\Chatbots\Models\ChatbotEvent;
use App\Domain\Chatbots\Models\ChatbotFlow;
use App\Domain\Chatbots\Models\ChatbotSession;
use App\Domain\Chatbots\Services\Chatbots;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\ConversationNote;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Runs & Activity: every chatbot session (a "run") with how it ended, and its replay — the steps it
 * went through, the paths taken and what was said, in the order it happened (chatbot_events.seq).
 * Read only, chatbots.view. Phone numbers are shown only to people who may see contacts.
 */
final class ChatbotRunsController extends Controller
{
    private const PAGE = 50;

    public function __construct(private readonly TenantContext $context, private readonly Chatbots $chatbots) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'flow' => ['nullable', 'uuid'],
            'number' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:running,completed,handed_off,abandoned,stopped'],
            'outcome' => ['nullable', 'string', 'max:120'],
            'q' => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'before' => ['nullable', 'date'],
        ]);
        $query = ChatbotSession::query()
            ->when($data['flow'] ?? null, fn ($q, $v) => $q->where('flow_id', $v))
            ->when($data['number'] ?? null, fn ($q, $v) => $q->where('phone_number_id', $v))
            ->when($data['status'] ?? null, fn ($q, $v) => $v === 'running' ? $q->whereIn('status', ChatbotSession::RUNNING) : $q->where('status', $v))
            ->when($data['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', Carbon::parse($v)->startOfDay()))
            ->when($data['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', Carbon::parse($v)->endOfDay()))
            ->when($data['before'] ?? null, fn ($q, $v) => $q->where('created_at', '<', Carbon::parse($v)))
            ->when($data['outcome'] ?? null, fn ($q, $v) => $q->whereExists(fn ($e) => $e->selectRaw('1')->from('chatbot_events')
                ->whereColumn('chatbot_events.session_id', 'chatbot_sessions.id')->whereIn('kind', ['handoff', 'complete'])->where('detail', $v)))
            ->when($data['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->whereIn('contact_id', Contact::query()->where(fn ($c) => $c->whereRaw('lower(name) LIKE ?', [$like])->orWhereRaw('lower(profile_name) LIKE ?', [$like])
                    ->when($this->context->can(Permission::ContactsView), fn ($c) => $c->orWhere('wa_id', 'like', $like)))->select('id'));
            });
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->limit(self::PAGE + 1)->get();
        $more = $rows->count() > self::PAGE;
        $rows = $rows->take(self::PAGE);

        $flows = ChatbotFlow::query()->whereIn('id', $rows->pluck('flow_id')->unique())->pluck('name', 'id');
        $contacts = Contact::query()->whereIn('id', $rows->pluck('contact_id')->unique())->get()->keyBy('id');
        $outcomes = ChatbotEvent::query()->whereIn('session_id', $rows->pluck('id'))->whereIn('kind', ['handoff', 'complete'])->whereNotNull('detail')
            ->orderBy('seq')->get(['session_id', 'detail'])->pluck('detail', 'session_id');
        $steps = ChatbotEvent::query()->whereIn('session_id', $rows->pluck('id'))->where('kind', 'enter')
            ->selectRaw('session_id, count(*) AS total')->groupBy('session_id')->toBase()->pluck('total', 'session_id');

        return response()->json([
            'data' => $rows->map(fn (ChatbotSession $s) => [
                'id' => $s->id,
                'flow' => ['id' => $s->flow_id, 'name' => $flows[$s->flow_id] ?? 'Deleted flow'],
                'version' => $s->flow_version,
                'status' => $s->status,
                'ended_reason' => $s->ended_reason,
                'outcome' => $outcomes[$s->id] ?? null,
                'steps' => (int) ($steps[$s->id] ?? 0),
                'contact' => $this->contact($contacts[$s->contact_id] ?? null),
                'conversation_id' => $s->conversation_id,
                'current_node' => $s->isRunning() ? $s->current_node : $s->ended_node,
                'started_at' => $s->created_at?->toIso8601String(),
                'ended_at' => $s->ended_at?->toIso8601String(),
            ])->values(),
            'meta' => ['more' => $more, 'next_before' => $more ? $rows->last()?->created_at?->toIso8601String() : null],
        ]);
    }

    /** One run replayed in order: steps entered, paths taken, messages received and sent, notes, and how it ended. */
    public function show(ChatbotSession $session): JsonResponse
    {
        $version = $this->chatbots->version($session->flow_id, $session->flow_version);
        $graph = $version[0] ?? null;
        $title = fn (string $id) => $graph?->node($id)['title'] ?? $id;
        $events = ChatbotEvent::query()->where('session_id', $session->id)->orderBy('seq')->get();

        $messages = Message::query()->where('conversation_id', $session->conversation_id)
            ->where(fn ($q) => $q->whereIn('id', $events->where('kind', 'received')->pluck('detail')->filter())
                ->orWhereIn('idempotency_key', $events->where('kind', 'sent')->pluck('detail')->filter()))
            ->get();
        $byId = $messages->keyBy('id');
        $byKey = $messages->whereNotNull('idempotency_key')->keyBy('idempotency_key');
        $notes = ConversationNote::query()->whereIn('id', $events->where('kind', 'note')->pluck('detail')->filter())->get()->keyBy('id');

        $timeline = [];
        foreach ($events as $e) {
            $at = $e->created_at?->toIso8601String();
            $item = ['seq' => $e->seq, 'at' => $at, 'node_id' => $e->node_id, 'node' => $title($e->node_id)];
            $timeline[] = match ($e->kind) {
                'received' => $item + ['kind' => 'customer'] + $this->message($byId[$e->detail] ?? null),
                'sent' => $item + ['kind' => 'bot'] + $this->message($byKey[$e->detail] ?? null),
                'note' => $item + ['kind' => 'note', 'text' => isset($notes[$e->detail]) ? (string) $notes[$e->detail]->getAttribute('body') : null],
                'click' => $item + ['kind' => 'path', 'output' => $e->detail, 'label' => $this->outputLabel($graph?->node($e->node_id), $e->detail)],
                default => $item + ['kind' => $e->kind, 'detail' => $e->detail],
            };
        }
        $contact = Contact::query()->find($session->contact_id);

        return response()->json(['data' => [
            'id' => $session->id,
            'flow' => ['id' => $session->flow_id, 'name' => ChatbotFlow::query()->whereKey($session->flow_id)->value('name') ?? 'Deleted flow'],
            'version' => $session->flow_version,
            'status' => $session->status,
            'ended_reason' => $session->ended_reason,
            'ended_node' => $session->ended_node !== null ? ['id' => $session->ended_node, 'title' => $title($session->ended_node)] : null,
            'current_node' => $session->isRunning() && $session->current_node !== null ? ['id' => $session->current_node, 'title' => $title($session->current_node)] : null,
            'contact' => $this->contact($contact),
            'conversation_id' => $session->conversation_id,
            'answers' => (object) array_filter((array) ($session->vars ?? []), fn ($v, $k) => ! str_starts_with((string) $k, '_') && $v !== null && $v !== '', ARRAY_FILTER_USE_BOTH),
            'started_at' => $session->created_at?->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            // Runs before November 2026 have no transcript events: their steps and paths still replay.
            'complete_log' => $events->contains(fn (ChatbotEvent $e) => in_array($e->kind, ChatbotEvent::LOG_KINDS, true)),
            'timeline' => $timeline,
        ]]);
    }

    /** @return array<string, mixed> */
    private function message(?Message $m): array
    {
        if ($m === null) {
            return ['text' => null, 'message_id' => null];
        }

        return [
            'message_id' => $m->id,
            'type' => $m->getAttribute('type'),
            'text' => $m->getAttribute('body') ?? ($m->getAttribute('template')['name'] ?? null),
            'status' => $m->status->value,
            'error' => $m->getAttribute('error_title'),
        ];
    }

    /** @param ?array<string, mixed> $node */
    private function outputLabel(?array $node, ?string $output): ?string
    {
        foreach ((array) ($node['outputs'] ?? []) as $o) {
            if (($o['id'] ?? null) === $output) {
                return $o['label'] ?? $output;
            }
        }

        return $output;
    }

    /** @return ?array<string, mixed> */
    private function contact(?Contact $c): ?array
    {
        if ($c === null) {
            return null;
        }

        return ['id' => $c->id, 'name' => $c->displayName(), 'phone' => $this->context->can(Permission::ContactsView) ? $c->wa_id : null];
    }
}
