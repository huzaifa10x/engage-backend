<?php

declare(strict_types=1);

namespace Tests\Feature\Chatbots;

use App\Domain\Access\SystemRole;
use App\Domain\Chatbots\Models\ChatbotFlow;
use App\Domain\Messaging\Models\Message;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsChatbots;
use Tests\TestCase;

/** Batch 5, item 3: welcome message and ice breakers per number (Bot settings). */
final class WelcomeIceBreakersTest extends TestCase
{
    use BuildsChatbots;

    /** @var list<array<string, mixed>> */
    private array $automation = [];

    private bool $metaRefuses = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootChatbots();
        Http::fake([
            'graph.facebook.com/*/conversational_automation*' => function (Request $r) {
                if ($this->metaRefuses) {
                    return Http::response(['error' => ['message' => 'Invalid parameter', 'code' => 100]], 400);
                }
                $this->automation[] = $r->data();

                return Http::response(['success' => true]);
            },
        ]);
        $this->actingAsMember($this->owner);
    }

    private function flow(string $name, string $reply, array $start = ['keywords' => ['never-typed'], 'keyword_match' => 'exact']): ChatbotFlow
    {
        return $this->liveFlow(['nodes' => [
            $this->node('start', 'start', ['any_message' => false] + $start, ['next' => 'hi']),
            $this->node('hi', 'message', ['text' => $reply], ['next' => 'team']),
            $this->node('team', 'handoff', ['text' => 'A person will reply.']),
        ]], name: $name);
    }

    public function test_ice_breakers_are_set_on_meta_and_tapping_one_starts_its_flow(): void
    {
        $booking = $this->flow('Booking', 'Let’s book your class.');
        $prices = $this->flow('Prices', 'Here are our prices.');
        $welcome = $this->flow('Welcome', 'Welcome to Nova Fitness!');

        $this->putJson("/api/v1/chatbots/welcome/{$this->number->id}", [
            'welcome_enabled' => true,
            'welcome_flow_id' => $welcome->id,
            'ice_breakers' => [['text' => 'Book a class', 'flow_id' => $booking->id], ['text' => 'See prices', 'flow_id' => $prices->id], ['text' => '', 'flow_id' => null]],
        ])->assertOk()->assertJsonPath('data.welcome_enabled', true)->assertJsonCount(2, 'data.ice_breakers');
        $this->assertSame([['enable_welcome_message' => true, 'prompts' => ['Book a class', 'See prices']]], $this->automation);

        $list = $this->getJson('/api/v1/chatbots/welcome')->assertOk()->json('data.0');
        $this->assertSame('Book a class', $list['settings']['ice_breakers'][0]['text']);
        $this->assertContains('Prices', array_column($list['flows'], 'name'));

        // A new chat: the customer taps "See prices" (WhatsApp sends its words as a text message).
        $this->says('See prices', '971500000001');
        $this->assertSame('Here are our prices.', $this->texts()[0]);
        // Another customer taps "Book a class".
        $this->sent = [];
        $this->says('book a class', '971500000002');
        $this->assertSame('Let’s book your class.', $this->texts()[0]);
        // Any other words: no ice breaker.
        $this->sent = [];
        $this->says('Book a class tomorrow?', '971500000003');
        $this->assertSame([], $this->texts());
    }

    public function test_the_welcome_flow_answers_when_someone_opens_the_chat_for_the_first_time(): void
    {
        $welcome = $this->flow('Welcome', 'Welcome to Nova Fitness!');
        $this->putJson("/api/v1/chatbots/welcome/{$this->number->id}", ['welcome_enabled' => true, 'welcome_flow_id' => $welcome->id, 'ice_breakers' => []])->assertOk();

        $this->customer(['type' => 'request_welcome'], '971500000010');
        $this->assertSame('Welcome to Nova Fitness!', $this->texts()[0]);
        $this->assertSame('👋 Opened the chat', $this->inTenant(fn () => Message::query()->where('type', 'request_welcome')->firstOrFail()->preview()));

        // Switched off: nothing answers.
        $this->putJson("/api/v1/chatbots/welcome/{$this->number->id}", ['welcome_enabled' => false, 'welcome_flow_id' => $welcome->id, 'ice_breakers' => []])->assertOk();
        $this->assertFalse(end($this->automation)['enable_welcome_message']);
        $this->sent = [];
        $this->customer(['type' => 'request_welcome'], '971500000011');
        $this->assertSame([], $this->texts());
    }

    public function test_limits_flows_and_permissions(): void
    {
        $booking = $this->flow('Booking', 'Booked.');
        $five = array_map(fn (int $i) => ['text' => "Question {$i}", 'flow_id' => $booking->id], range(1, 5));
        $url = "/api/v1/chatbots/welcome/{$this->number->id}";
        $this->putJson($url, ['welcome_enabled' => false, 'ice_breakers' => $five])->assertUnprocessable()->assertJsonFragment(['ice_breakers' => ['WhatsApp shows up to 4 ice breakers.']]);
        $this->putJson($url, ['welcome_enabled' => false, 'ice_breakers' => [['text' => str_repeat('a', 81), 'flow_id' => $booking->id]]])->assertUnprocessable();
        $this->putJson($url, ['welcome_enabled' => false, 'ice_breakers' => [['text' => 'Book now 🎉', 'flow_id' => $booking->id]]])
            ->assertUnprocessable()->assertJsonFragment(['ice_breakers.0.text' => ['WhatsApp does not allow emoji in ice breakers.']]);
        $this->putJson($url, ['welcome_enabled' => false, 'ice_breakers' => [['text' => 'Book', 'flow_id' => null]]])->assertUnprocessable();
        $this->putJson($url, ['welcome_enabled' => true, 'ice_breakers' => []])->assertUnprocessable();
        $other = $this->inTenant(fn () => ChatbotFlow::query()->create(['name' => 'Elsewhere', 'kind' => 'bot', 'draft' => ['nodes' => []]]));
        $this->putJson($url, ['welcome_enabled' => false, 'ice_breakers' => [['text' => 'Book', 'flow_id' => $other->id]]])
            ->assertUnprocessable()->assertJsonFragment(['ice_breakers.0.flow_id' => ['Choose a published flow of this number.']]);
        $this->assertSame([], $this->automation, 'nothing reaches Meta when the settings are not valid');

        // Meta refuses: nothing is kept.
        $this->metaRefuses = true;
        $this->putJson($url, ['welcome_enabled' => false, 'ice_breakers' => [['text' => 'Book', 'flow_id' => $booking->id]]])->assertUnprocessable();
        $this->assertSame([], $this->getJson('/api/v1/chatbots/welcome')->json('data.0.settings.ice_breakers'));

        // Agents can look, not change.
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Agent));
        $this->putJson($url, ['welcome_enabled' => false, 'ice_breakers' => []])->assertForbidden();
    }
}
