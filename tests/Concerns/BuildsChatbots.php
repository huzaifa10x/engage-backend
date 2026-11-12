<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Chatbots\Models\ChatbotFlow;
use App\Domain\Chatbots\Models\ChatbotSession;
use App\Domain\Developer\Services\UrlGuard;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A workspace with a connected number, an owner, Meta faked at its HTTP endpoint (every message
 * sent is kept in $sent) and outside APIs faked (calls kept in $outside), for chatbot tests that
 * drive the real stack: signed webhook → inbox → Chatbots → FlowEngine → LiveRuntime → SendMessage.
 *
 * @phpstan-require-extends TestCase
 */
trait BuildsChatbots
{
    use InteractsWithWhatsapp;

    protected Tenant $tenant;

    protected TenantMembership $owner;

    protected PhoneNumber $number;

    /** @var list<array<string, mixed>> */
    protected array $sent = [];

    /** @var list<string> */
    protected array $outside = [];

    protected function bootChatbots(): void
    {
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->owner = $this->addMember($this->tenant);
        $this->number = $this->connectNumber($this->tenant);
        $this->app->bind(UrlGuard::class, fn () => new class extends UrlGuard
        {
            protected function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        Http::preventStrayRequests();
        Http::fake([
            'graph.facebook.com/*/messages*' => function (Request $r) {
                $this->sent[] = $r->data();

                return Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.OUT'.bin2hex(random_bytes(5))]]]);
            },
            'api.example.com/*' => function (Request $r) {
                $this->outside[] = $r->url();

                return Http::response(['lead' => ['id' => 'L-1']]);
            },
        ]);
    }

    /** @template T @param callable(): T $fn @return T */
    protected function inTenant(callable $fn): mixed
    {
        return $this->tenantContext()->run($this->tenant, $fn);
    }

    /** @param array<string, mixed> $message */
    protected function customer(array $message, string $waId = '971501234567', string $name = 'Sara Ahmed'): void
    {
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue($message, waId: $waId, bsuid: null, name: $name)))->assertOk();
    }

    protected function says(string $text, string $waId = '971501234567'): void
    {
        $this->customer(['text' => ['body' => $text]], $waId);
    }

    protected function taps(string $id, string $title, string $waId = '971501234567'): void
    {
        $this->customer(['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => $title]]], $waId);
    }

    /**
     * Creates, publishes and switches on a flow through the API, as the owner.
     *
     * @param  array<string, mixed>  $graph
     * @param  array<string, mixed>  $settings
     */
    protected function liveFlow(array $graph, array $settings = [], string $name = 'Bot'): ChatbotFlow
    {
        $this->actingAsMember($this->owner);
        $id = $this->postJson('/api/v1/chatbots', ['name' => $name, 'phone_number_id' => $this->number->id, 'template' => 'blank'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/chatbots/{$id}", array_filter(['draft' => $graph, 'settings' => $settings ?: null]))->assertOk();
        $publish = $this->postJson("/api/v1/chatbots/{$id}/publish");
        $this->assertSame(200, $publish->status(), (string) json_encode($publish->json('error.details.issues') ?? $publish->json(), JSON_UNESCAPED_UNICODE));
        $this->postJson("/api/v1/chatbots/{$id}/activate", ['active' => true])->assertOk();

        return $this->inTenant(fn () => ChatbotFlow::query()->findOrFail($id));
    }

    protected function botSession(?string $flowId = null): ChatbotSession
    {
        return $this->inTenant(fn () => ChatbotSession::query()->when($flowId !== null, fn ($q) => $q->where('flow_id', $flowId))->latest('created_at')->firstOrFail());
    }

    protected function contact(string $waId = '971501234567'): Contact
    {
        return $this->inTenant(fn () => Contact::query()->where('wa_id', $waId)->sole());
    }

    protected function approvedTemplate(string $name): void
    {
        $this->inTenant(fn () => MessageTemplate::query()->create([
            'waba_account_id' => $this->number->waba_account_id, 'name' => $name, 'language' => 'en', 'status' => 'APPROVED', 'category' => 'MARKETING',
            'components' => [['type' => 'BODY', 'text' => 'New listings are in. Reply to continue.']],
        ]));
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, ?string>  $outputs  output id => target node id
     * @return array<string, mixed>
     */
    protected function node(string $id, string $type, array $config, array $outputs = []): array
    {
        return ['id' => $id, 'type' => $type, 'title' => ucfirst($id), 'config' => $config, 'outputs' => array_map(fn ($to, $out) => ['id' => $out, 'to' => $to], $outputs, array_keys($outputs))];
    }

    /** @return list<?string> the text of every message sent */
    protected function texts(): array
    {
        return array_map(fn ($m) => $m['text']['body'] ?? $m['interactive']['body']['text'] ?? ($m['template']['name'] ?? null), $this->sent);
    }
}
