<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Engine;

use App\Domain\Chatbots\Models\ChatbotFlow;
use App\Domain\Chatbots\Models\ChatbotFlowVersion;
use App\Domain\Chatbots\Models\ChatbotSecret;
use App\Domain\Chatbots\Services\ChatbotWorkspace;
use App\Domain\Chatbots\Services\OrderLookup;
use App\Domain\Crm\Models\Segment;
use App\Domain\Crm\Services\CustomFields;
use App\Domain\Crm\Services\SegmentQuery;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Services\InboxTools;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Infrastructure\Secrets\SecretStore;
use Throwable;

/**
 * The builder's Test chat: runs the real engine on the draft, against a real contact's data
 * when one is chosen ("preview as contact"). Nothing is sent on WhatsApp and nothing is written:
 * field and tag changes stay in this test session. API-call blocks do call their URL.
 */
final class TestRuntime extends BotRuntime
{
    /** @var list<array<string, mixed>> what the customer would see, and notes about what happened */
    public array $transcript = [];

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $contact
     */
    public function __construct(array $state, array $contact, private readonly string $botName = 'Chatbot')
    {
        $this->state = $state + [
            'current_node' => null, 'awaiting_input' => false, 'retry_count' => 0, 'vars' => [], 'status' => 'active',
            'steps' => 0, 'resume_at' => null, 'timeout_at' => null, 'ended_reason' => null, 'ended_node' => null,
        ];
        $this->contact = $contact;
    }

    /** @return array<string, mixed> */
    public static function contactFor(?Contact $contact): array
    {
        return $contact !== null ? LiveRuntime::snapshot($contact) : [
            'id' => null, 'name' => 'Test Customer', 'first_name' => 'Test', 'email' => null, 'phone' => '+971500000000',
            'fields' => [], 'tags' => [], 'consent' => 'unknown',
        ];
    }

    public function isTest(): bool
    {
        return true;
    }

    public function send(array $data): ?string
    {
        $this->state['steps']++;
        $this->transcript[] = ['kind' => 'bot', 'message' => $data];

        return null;
    }

    public function updateContact(array $changes): void
    {
        if (isset($changes['fields'])) {
            // Same checks as a real save, so the test shows a value that would be refused.
            $this->contact['fields'] = app(CustomFields::class)->values(array_merge($this->contact['fields'], $changes['fields']), false);
        }
        foreach (['name', 'email'] as $key) {
            if (array_key_exists($key, $changes)) {
                $this->contact[$key] = $changes[$key];
            }
        }
        if (isset($changes['tags'])) {
            $this->contact['tags'] = $changes['tags'];
        }
    }

    public function assign(string $mode, ?string $memberId, string $reason): string
    {
        if ($mode === 'member' && $memberId !== null) {
            return TenantMembership::query()->find($memberId)?->user()->value('name') ?? 'the chosen teammate';
        }

        return $mode === 'auto' ? 'the least busy teammate' : 'your team (per your inbox routing)';
    }

    public function closeConversation(): void {}

    public function inSegment(string $segment): bool
    {
        if ($this->contact['id'] === null) {
            return false;
        }
        $found = Segment::query()->where('name', 'ilike', $segment)->orWhere(fn ($q) => $q->when(preg_match('/^[0-9a-f-]{36}$/i', $segment) === 1, fn ($w) => $w->where('id', $segment)))->first();

        return $found !== null && app(SegmentQuery::class)->apply(Contact::query()->whereKey($this->contact['id']), $found->match, (array) $found->rules)->exists();
    }

    public function businessOpen(): bool
    {
        return InboxTools::isOpen(app(TenantContext::class)->tenantOrNull());
    }

    public function openDays(): array
    {
        return ChatbotWorkspace::openDays(app(TenantContext::class)->tenantOrNull());
    }

    public function timezone(): string
    {
        return app(TenantContext::class)->tenantOrNull()->timezone ?? 'UTC';
    }

    /** A subflow's published version; one never published is tested from its draft. */
    public function graph(string $flowId): ?FlowGraph
    {
        $flow = ChatbotFlow::query()->whereKey($flowId)->where('kind', 'subflow')->first();
        if ($flow === null) {
            return null;
        }
        $row = $flow->published_version !== null ? ChatbotFlowVersion::query()->where('flow_id', $flowId)->where('version', $flow->published_version)->first() : null;

        return FlowGraph::from($row !== null ? $row->graph : $flow->draft);
    }

    public function optOut(string $scope): void
    {
        $this->note($scope === 'all' ? '(Test) The customer would be opted out of all messages.' : '(Test) The customer would be opted out of marketing messages.');
    }

    public function order(string $number): ?array
    {
        return $this->contact['id'] !== null ? app(OrderLookup::class)->find($number, $this->contact['phone'], $this->contact['id']) : null;
    }

    public function internalNote(string $text): void
    {
        $this->transcript[] = ['kind' => 'internal', 'author' => $this->botName, 'text' => $text];
    }

    public function botName(): string
    {
        return $this->botName;
    }

    public function business(): array
    {
        return ChatbotWorkspace::variables(app(TenantContext::class)->tenantOrNull());
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
        // Test runs never count in the flow's analytics.
    }

    public function note(string $text): void
    {
        $this->transcript[] = ['kind' => 'note', 'text' => $text];
    }
}
