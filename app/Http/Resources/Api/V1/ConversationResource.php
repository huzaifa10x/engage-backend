<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Chatbots\Models\ChatbotFlowVersion;
use App\Domain\Chatbots\Models\ChatbotSession;
use App\Domain\Messaging\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Conversation */
final class ConversationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phone_number_id' => $this->phone_number_id,
            'status' => $this->status->value,
            'snoozed_until' => ($until = $this->getAttribute('snoozed_until')) !== null && $until->isFuture() ? $until->toIso8601String() : null,
            'auto_reply_enabled' => (bool) ($this->getAttribute('auto_reply_enabled') ?? true),
            'assigned_membership_id' => $this->assigned_membership_id,
            // Who answers now: "bot" (a chatbot session is running), "human", or null (nobody yet).
            'handler' => $this->getAttribute('handler'),
            'bot' => $this->bot(),
            'unread_count' => $this->unread_count,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'last_message_preview' => $this->getAttribute('last_message_preview'),
            'last_message_direction' => $this->getAttribute('last_message_direction'),
            'window' => [
                'open' => $this->isWindowOpen(),
                'expires_at' => $this->window_expires_at?->toIso8601String(),
                // Whether a normal message can be sent now (window open, and for an opted-out contact: they wrote after opting out).
                'replies_allowed' => $this->relationLoaded('contact') && $this->contact !== null ? $this->acceptsFreeFormFrom($this->contact) : $this->isWindowOpen(),
            ],
            'contact' => ContactResource::make($this->whenLoaded('contact')),
            'phone_number' => $this->whenLoaded('phoneNumber', fn () => [
                'id' => $this->phoneNumber?->id,
                'display_phone_number' => $this->phoneNumber?->display_phone_number,
                'verified_name' => $this->phoneNumber?->verified_name,
                // "disconnected" once the number was offboarded: the conversation stays readable, but nothing can be sent.
                'status' => $this->phoneNumber?->status->value,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * The conversation's latest chatbot run: which flow, its state, and the step the customer
     * reached (for an abandoned flow: the step they left at).
     *
     * @return ?array<string, mixed>
     */
    private function bot(): ?array
    {
        $id = $this->getAttribute('bot_session_id');
        if ($id === null) {
            return null;
        }
        $session = ChatbotSession::query()->with('flow:id,name')->find($id);
        if ($session === null) {
            return null;
        }
        $node = $session->ended_node ?? $session->current_node;

        return [
            'state' => $this->getAttribute('bot_state'),
            'flow_id' => $session->flow_id,
            'flow_name' => $session->flow?->name,
            'node_id' => $node,
            'node_title' => $node !== null ? $this->nodeTitle($session, $node) : null,
            'ended_reason' => $session->ended_reason,
            'ended_at' => $session->ended_at?->toIso8601String(),
            'started_at' => $session->created_at?->toIso8601String(),
        ];
    }

    private function nodeTitle(ChatbotSession $session, string $node): ?string
    {
        // Node titles per flow version, read once per request (a page lists many conversations).
        static $titles = [];
        $key = "{$session->flow_id}:{$session->flow_version}";
        if (! isset($titles[$key])) {
            $graph = ChatbotFlowVersion::query()->where('flow_id', $session->flow_id)->where('version', $session->flow_version)->first()?->graph;
            $titles[$key] = collect(is_array($graph) ? (array) ($graph['nodes'] ?? []) : [])
                ->filter(fn ($n) => is_array($n) && isset($n['id']))->mapWithKeys(fn (array $n) => [$n['id'] => $n['title'] ?? null])->all();
        }

        return $titles[$key][$node] ?? null;
    }
}
