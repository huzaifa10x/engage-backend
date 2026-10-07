<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Domain\WhatsApp\Enums\OnboardingType;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** A conversation reads in the order things happened, however late older messages are imported. */
final class MessageOrderTest extends TestCase
{
    use InteractsWithWhatsapp;

    private const META = ['display_phone_number' => '971585496310', 'phone_number_id' => '106540352242922'];

    public function test_imported_history_sits_where_it_belongs_in_time(): void
    {
        $this->configureMeta();
        $tenant = $this->createTenant();
        $this->subscribe($tenant, 'pro');
        $owner = $this->addMember($tenant);
        $this->connectNumber($tenant, type: OnboardingType::Coexistence);

        // Today: the customer writes (a live message).
        $now = now();
        $live = $this->inboundValue(waId: '971501100000');
        $live['messages'][0] = ['from' => '971501100000', 'id' => 'wamid.TODAY1', 'timestamp' => (string) $now->timestamp, 'type' => 'text', 'text' => ['body' => 'Hello, are you open today?']];
        $this->postWebhook($this->webhookBody('messages', $live))->assertOk();
        $this->actingAsMember($owner);
        $conversation = $this->getJson('/api/v1/conversations')->assertOk()->json('data.0.id');

        // Afterwards, history from last month, last week and yesterday is imported for the same customer.
        $old = fn (string $id, int $daysAgo, string $text) => ['from' => '971501100000', 'id' => $id, 'timestamp' => (string) $now->copy()->subDays($daysAgo)->timestamp, 'type' => 'text', 'text' => ['body' => $text], 'history_context' => ['status' => 'READ']];
        $this->postWebhook($this->webhookBody('history', ['messaging_product' => 'whatsapp', 'metadata' => self::META, 'history' => [[
            'metadata' => ['phase' => 1, 'chunk_order' => 1, 'progress' => 100],
            'threads' => [['id' => '971501100000', 'messages' => [$old('wamid.WEEK', 7, 'Last week'), $old('wamid.MONTH', 30, 'Last month'), $old('wamid.YESTERDAY', 1, 'Yesterday')]]],
        ]]]))->assertOk();
        $this->actingAsMember($owner);

        // Newest first, by when each message happened: today's message is on top, not the imported ones.
        $page = $this->getJson("/api/v1/conversations/{$conversation}/messages?per_page=2")->assertOk();
        $this->assertSame(['Hello, are you open today?', 'Yesterday'], array_column($page->json('data'), 'body'));

        // "Load earlier" continues backwards in time, never forwards.
        $earlier = $this->getJson("/api/v1/conversations/{$conversation}/messages?per_page=2&cursor=".$page->json('meta.next_cursor'))->assertOk();
        $this->assertSame(['Last week', 'Last month'], array_column($earlier->json('data'), 'body'));
        $times = array_map(fn (string $t) => strtotime($t), array_merge(array_column($page->json('data'), 'timestamp'), array_column($earlier->json('data'), 'timestamp')));
        $sorted = $times;
        rsort($sorted);
        $this->assertSame($sorted, $times, 'every page and every row is older than the one before it');

        // The conversation list still shows today's message as the latest.
        $this->getJson('/api/v1/conversations')->assertJsonPath('data.0.last_message_preview', 'Hello, are you open today?');
    }
}
