<?php

declare(strict_types=1);

namespace Tests\Feature\Chatbots;

use App\Domain\Access\SystemRole;
use App\Domain\Chatbots\Models\ChatbotFlow;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsChatbots;
use Tests\TestCase;

/**
 * Start rules (D5): the triggers of every flow on a number in routing order, a priority list per
 * number, overlap detection with the same matcher routing uses, and a tester that asks routing itself.
 */
final class ChatbotStartRulesTest extends TestCase
{
    use BuildsChatbots;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootChatbots();
    }

    /** @param array<string, mixed> $start */
    private function flow(string $name, array $start, string $reply): ChatbotFlow
    {
        return $this->liveFlow(['nodes' => [
            $this->node('start', 'start', ['any_message' => false] + $start, ['next' => 'hi']),
            $this->node('hi', 'message', ['text' => $reply], ['next' => 'team']),
            $this->node('team', 'handoff', ['text' => 'A person will reply.']),
        ]], name: $name);
    }

    public function test_two_keyword_flows_overlap_and_the_number_priority_decides_deterministically(): void
    {
        $offers = $this->flow('Offers', ['keywords' => ['price'], 'keyword_match' => 'contains'], 'Our offers');
        $prices = $this->flow('Price list', ['keywords' => ['price list'], 'keyword_match' => 'starts'], 'Our price list');

        $rules = $this->getJson('/api/v1/chatbots/start-rules')->assertOk()->json('data.0');
        $this->assertSame([$offers->id, $prices->id], array_column($rules['rules'], 'flow_id'), 'routing order: earlier published first');
        $keyword = collect($rules['overlaps'])->firstWhere('example', 'price list');
        $this->assertSame('Offers', $keyword['winner']['name']);
        $this->assertTrue($keyword['same_kind']);
        $this->assertNotNull(collect($rules['overlaps'])->firstWhere('example', '(Click-to-WhatsApp ad)'), 'both also catch ads');

        $this->says('price list please', '971500000001');
        $this->assertSame('Our offers', $this->texts()[0]);

        // Put "Price list" first: the same message now starts it, every time.
        $this->putJson('/api/v1/chatbots/start-rules/order', ['phone_number_id' => $this->number->id, 'flow_ids' => [$prices->id, $offers->id]])->assertOk();
        $this->assertSame('Price list', collect($this->getJson('/api/v1/chatbots/start-rules')->json('data.0.overlaps'))->firstWhere('example', 'price list')['winner']['name']);
        $this->says('price list please', '971500000002');
        $this->assertSame('Our price list', $this->texts()[2]);

        $test = $this->postJson('/api/v1/chatbots/start-rules/test', ['phone_number_id' => $this->number->id, 'text' => 'Price list please'])->assertOk()->json('data');
        $this->assertSame($prices->id, $test['flow']['id']);
        $this->assertSame('keyword', $test['reason']);
        $this->assertTrue($this->inTenant(fn () => DB::table('audit_log')->where('action', 'chatbot.start_rules_ordered')->exists()));
    }

    public function test_a_campaign_reply_wins_over_a_keyword_whatever_the_order(): void
    {
        $keyword = $this->flow('Keyword', ['keywords' => ['yes please']], 'Keyword flow');
        $campaign = $this->flow('Campaign', ['campaign_buttons' => ['Yes please']], 'Campaign flow');
        $this->putJson('/api/v1/chatbots/start-rules/order', ['phone_number_id' => $this->number->id, 'flow_ids' => [$keyword->id, $campaign->id]])->assertOk();

        $overlaps = collect($this->getJson('/api/v1/chatbots/start-rules')->json('data.0.overlaps'));
        $overlap = $overlaps->firstWhere('example', 'yes please');
        // Both also catch Click-to-WhatsApp ads (the Start step default): reported too, decided by the list.
        $this->assertSame('Keyword', $overlaps->firstWhere('example', '(Click-to-WhatsApp ad)')['winner']['name']);
        $this->assertSame('Campaign', $overlap['winner']['name']);
        $this->assertFalse($overlap['same_kind']);

        $this->customer(['type' => 'button', 'button' => ['payload' => 'Yes please', 'text' => 'Yes please'], 'text' => null]);
        $this->assertSame('Campaign flow', $this->texts()[0]);
        $this->assertSame('Campaign', $this->postJson('/api/v1/chatbots/start-rules/test', ['phone_number_id' => $this->number->id, 'text' => 'Yes please', 'button' => true])->json('data.flow.name'));
    }

    public function test_no_match_and_greeting_and_default_are_explained(): void
    {
        $this->flow('Welcome', ['greeting' => true], 'Welcome!');
        $this->assertNull($this->postJson('/api/v1/chatbots/start-rules/test', ['phone_number_id' => $this->number->id, 'text' => 'hello'])->json('data.flow'));
        $this->assertSame('greeting', $this->postJson('/api/v1/chatbots/start-rules/test', ['phone_number_id' => $this->number->id, 'text' => 'hello', 'first_message' => true])->json('data.reason'));
        $this->assertSame([], $this->sent, 'the tester never sends anything');
    }

    public function test_viewers_see_but_cannot_reorder_and_another_workspace_cannot_touch_the_order(): void
    {
        $a = $this->flow('A', ['keywords' => ['a']], 'A');
        $b = $this->flow('B', ['keywords' => ['b']], 'B');
        $order = fn () => $this->inTenant(fn () => ChatbotFlow::query()->orderBy('start_priority')->orderBy('published_at')->pluck('id')->all());
        $before = $order();
        $this->assertSame([$a->id, $b->id], $before);
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Viewer));
        $this->getJson('/api/v1/chatbots/start-rules')->assertOk();
        $this->putJson('/api/v1/chatbots/start-rules/order', ['phone_number_id' => $this->number->id, 'flow_ids' => [$b->id, $a->id]])->assertForbidden();

        $other = $this->createTenant();
        $this->subscribe($other, 'pro');
        $this->actingAsMember($this->addMember($other));
        $this->assertSame([], $this->getJson('/api/v1/chatbots/start-rules')->assertOk()->json('data'));
        $this->putJson('/api/v1/chatbots/start-rules/order', ['phone_number_id' => $this->number->id, 'flow_ids' => [$b->id, $a->id]])->assertUnprocessable();
        $this->assertSame($before, $order(), 'the order is unchanged');
    }
}
