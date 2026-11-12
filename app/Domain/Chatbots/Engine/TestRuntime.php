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
use App\Domain\Messaging\Exceptions\MessagingException;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\InboxTeam;
use App\Domain\Messaging\Services\BusinessHours;
use App\Domain\Messaging\Services\InboxTools;
use App\Domain\Templates\Services\TemplateMessage;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Infrastructure\Secrets\SecretStore;
use Throwable;

/**
 * The builder's Test chat: runs the real engine on the draft, against a real contact's data
 * when one is chosen ("preview as contact"). Nothing is sent on WhatsApp and nothing is written:
 * field and tag changes stay in this test session.
 *
 * Integrations are dry runs by default: API and Google Sheets blocks are not sent; the transcript
 * shows what would have been sent and the step's sample answer (or the chosen failure) is used.
 * Real calls happen only with integrations = "live" (people who may publish and manage
 * integrations, after confirming, with a reason), and only to the workspace's test endpoints:
 * any other address stays a dry run. Every real call is listed in $liveCalls for the audit log.
 *
 * Options: number_id (whose business hours and templates apply), integrations (mock | live),
 * test_endpoints (URL prefixes live calls may reach), max_live_calls (per request),
 * api_mode (success | error | timeout, for mocked calls), window (open | closed).
 */
final class TestRuntime extends BotRuntime
{
    /** @var list<array<string, mixed>> what the customer would see, and notes about what happened */
    public array $transcript = [];

    /** @var list<array<string, mixed>> real calls made in this request (method, host, path, node, status) */
    public array $liveCalls = [];

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $contact
     * @param  array<string, mixed>  $options
     */
    public function __construct(array $state, array $contact, private readonly string $botName = 'Chatbot', private readonly array $options = [])
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
            'fields' => [], 'tags' => [], 'consent' => 'unknown', 'lead_source' => null, 'campaign' => null,
        ];
    }

    public function isTest(): bool
    {
        return true;
    }

    public function windowClosed(): bool
    {
        return ($this->state['vars']['_test_window'] ?? ($this->options['window'] ?? 'open')) === 'closed';
    }

    /** A customer message opens the window; a wait of 24 hours or more closes it. */
    public function setWindow(bool $open): void
    {
        $this->state['vars']['_test_window'] = $open ? 'open' : 'closed';
    }

    public function liveCalls(): bool
    {
        return ($this->options['integrations'] ?? 'mock') === 'live';
    }

    public function mayCallLive(string $url): bool
    {
        if (count($this->liveCalls) >= (int) ($this->options['max_live_calls'] ?? 5)) {
            $this->note('(Test) Not sent: the limit of live calls for one message was reached.');

            return false;
        }
        if (! ChatbotWorkspace::isTestEndpoint($url, (array) ($this->options['test_endpoints'] ?? []))) {
            $this->note('(Test) Not sent for real: '.parse_url($url, PHP_URL_HOST).' is not one of the workspace\'s test endpoints, so the sample answer is used.');

            return false;
        }

        return true;
    }

    public function liveCallMade(array $call): void
    {
        $this->liveCalls[] = $call;
    }

    public function apiMode(): string
    {
        return in_array($this->options['api_mode'] ?? null, ['error', 'timeout'], true) ? $this->options['api_mode'] : 'success';
    }

    public function send(array $data): ?string
    {
        $template = ($data['type'] ?? null) === 'template';
        // The 24-hour window of this test conversation (set in the test settings, opened again by every
        // customer message, closed by a wait of a day or more): free text fails like it would live.
        if (! $template && $this->windowClosed()) {
            throw new BotSendFailed('WhatsApp only delivers approved templates outside the 24-hour window.', 'window_closed');
        }
        if ($template) {
            $this->checkTemplate($data['template'] ?? []);
        }
        $this->state['steps']++;
        $this->transcript[] = ['kind' => 'bot', 'message' => $data];

        return null;
    }

    /**
     * The same check a live send makes (TemplateMessage::prepare): the template exists on the number's
     * WhatsApp account, is approved and its variables fit. Nothing is sent.
     *
     * @param  array<string, mixed>  $template
     */
    private function checkTemplate(array $template): void
    {
        $number = isset($this->options['number_id']) ? PhoneNumber::query()->find($this->options['number_id']) : null;
        if ($number === null) {
            $this->note('(Test) No WhatsApp number chosen, so the template “'.($template['name'] ?? '').'” was not checked.');

            return;
        }
        try {
            app(TemplateMessage::class)->prepare($number, $template, null);
        } catch (MessagingException $e) {
            throw new BotSendFailed($e->getMessage(), 'template_unavailable');
        }
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
        // As LiveRuntime::snapshot() does after a save, so “Thanks {{contact.first_name}}” matches live.
        if (array_key_exists('name', $changes) && is_string($changes['name']) && trim($changes['name']) !== '') {
            $this->contact['first_name'] = explode(' ', trim($changes['name']))[0];
        }
        if (isset($changes['tags'])) {
            $this->contact['tags'] = $changes['tags'];
        }
    }

    public function assign(string $mode, ?string $memberId, string $reason, ?string $teamId = null): string
    {
        if ($mode === 'team' && $teamId !== null) {
            $team = InboxTeam::query()->find($teamId);

            return $team !== null ? "the {$team->name} team (its least busy member)" : 'a team that no longer exists';
        }
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
        // The number's own hours, as LiveRuntime does for the conversation's number.
        return InboxTools::isOpen(app(TenantContext::class)->tenantOrNull(), null, $this->options['number_id'] ?? null);
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

    public function optIn(): void
    {
        $this->note('(Test) The customer would be opted in to messages, marketing included.');
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
        $tenant = app(TenantContext::class)->tenantOrNull();

        return ChatbotWorkspace::variables($tenant) + ['next_open' => BusinessHours::nextOpenLabel($tenant)];
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
