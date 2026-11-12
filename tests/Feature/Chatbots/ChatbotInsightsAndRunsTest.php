<?php

declare(strict_types=1);

namespace Tests\Feature\Chatbots;

use App\Domain\Access\Models\Role;
use App\Domain\Access\SystemRole;
use App\Domain\Chatbots\Models\ChatbotFlow;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsChatbots;
use Tests\TestCase;

/**
 * Version-aware Flow insights (D3) and Runs & Activity with replay (D4), from real runs through the
 * webhook, with the counts checked against the raw tables.
 */
final class ChatbotInsightsAndRunsTest extends TestCase
{
    use BuildsChatbots;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootChatbots();
    }

    /** @return array<string, mixed> */
    private function graph(string $question): array
    {
        return ['nodes' => [
            $this->node('start', 'start', ['any_message' => true], ['next' => 'menu']),
            $this->node('menu', 'buttons', ['text' => $question, 'buttons' => [['id' => 'buy', 'title' => 'Buy'], ['id' => 'rent', 'title' => 'Rent']]], ['buy' => 'team', 'rent' => 'bye']),
            $this->node('bye', 'end', ['text' => 'Thanks!', 'outcome' => 'Renter']),
            $this->node('team', 'handoff', ['text' => 'A person will reply.', 'outcome' => 'Qualified lead']),
        ]];
    }

    private function republish(ChatbotFlow $flow, string $question): void
    {
        $this->patchJson("/api/v1/chatbots/{$flow->id}", ['draft' => $this->graph($question)])->assertOk();
        $this->postJson("/api/v1/chatbots/{$flow->id}/publish")->assertOk();
    }

    public function test_a_changed_step_counts_from_its_new_version_and_unchanged_steps_keep_their_history(): void
    {
        $flow = $this->liveFlow($this->graph('Buy or rent?'));
        $this->says('hi', '971500000001');
        $this->taps('bot:menu:buy', 'Buy', '971500000001');
        $this->says('hi', '971500000002');
        $this->taps('bot:menu:rent', 'Rent', '971500000002');

        // v2 changes the question: the menu step is a different step now; "team" and "bye" are not.
        $this->republish($flow, 'Are you buying or renting?');
        $this->says('hi', '971500000003');
        $this->taps('bot:menu:buy', 'Buy', '971500000003');

        $current = $this->getJson("/api/v1/chatbots/{$flow->id}/analytics")->assertOk()->json('data');
        $this->assertSame('current', $current['scope']);
        $this->assertSame(2, $current['version']);
        $this->assertSame(1, $current['nodes']['menu']['entries'], 'the changed question counts from version 2 only');
        $this->assertSame([2], $current['nodes']['menu']['counted_versions']);
        $this->assertSame(2, $current['nodes']['team']['entries'], 'the unchanged handover keeps version 1’s runs');
        $this->assertSame([1, 2], $current['nodes']['team']['counted_versions']);
        $this->assertSame(1, $current['sessions']['started'], 'runs of the published version');
        $this->assertSame(1, collect($current['versions'])->firstWhere('version', 1)['changed_steps']);

        $v1 = $this->getJson("/api/v1/chatbots/{$flow->id}/analytics?version=1")->assertOk()->json('data');
        $this->assertSame(2, $v1['nodes']['menu']['entries']);
        $this->assertSame(['buy' => 1, 'rent' => 1], $v1['nodes']['menu']['clicks']);
        $this->assertSame(2, $v1['sessions']['started']);

        $all = $this->getJson("/api/v1/chatbots/{$flow->id}/analytics?version=all")->assertOk()->json('data');
        $this->assertSame(3, $all['nodes']['menu']['entries']);
        $this->assertEqualsCanonicalizing([['label' => 'Qualified lead', 'count' => 2], ['label' => 'Renter', 'count' => 1]], $all['outcomes']);

        // The totals equal the raw tables.
        $raw = $this->inTenant(fn () => DB::table('chatbot_events')->where('flow_id', $flow->id)->where('node_id', 'menu')->where('kind', 'enter')->count(DB::raw('DISTINCT session_id')));
        $this->assertSame($raw, $all['nodes']['menu']['entries']);
        $this->assertSame($this->inTenant(fn () => DB::table('chatbot_sessions')->where('flow_id', $flow->id)->count()), $all['sessions']['started']);
        // The run log (received / sent / note) never counts as errors.
        $this->assertSame(0, $all['nodes']['menu']['errors']);
    }

    public function test_runs_list_filters_and_replays_a_run_in_order(): void
    {
        $flow = $this->liveFlow($this->graph('Buy or rent?'), name: 'Property enquiries');
        $this->says('hello there', '971500000001');
        $this->taps('bot:menu:buy', 'Buy', '971500000001');
        $this->says('hi', '971500000002');

        $runs = $this->getJson('/api/v1/chatbots/runs')->assertOk()->json('data');
        $this->assertCount(2, $runs);
        $done = collect($runs)->firstWhere('status', 'handed_off');
        $this->assertSame('Property enquiries', $done['flow']['name']);
        $this->assertSame('Qualified lead', $done['outcome']);
        $this->assertSame('971500000001', $done['contact']['phone']);

        $this->assertCount(1, $this->getJson('/api/v1/chatbots/runs?status=running')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/chatbots/runs?outcome=Qualified%20lead')->json('data'));
        $this->assertCount(2, $this->getJson("/api/v1/chatbots/runs?flow={$flow->id}")->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/chatbots/runs?q=0000002')->json('data'));

        $run = $this->getJson("/api/v1/chatbots/runs/{$done['id']}")->assertOk()->json('data');
        $this->assertTrue($run['complete_log']);
        $replay = array_map(fn ($i) => [$i['kind'], $i['node_id'], $i['text'] ?? $i['label'] ?? $i['detail'] ?? null], $run['timeline']);
        $this->assertSame([
            ['customer', 'start', 'hello there'],
            ['enter', 'start', null],
            ['enter', 'menu', null],
            ['bot', 'menu', 'Buy or rent?'],
            ['customer', 'menu', 'Buy'],
            ['path', 'menu', 'Buy'],
            ['enter', 'team', null],
            ['bot', 'team', 'A person will reply.'],
            ['note', 'team', $run['timeline'][8]['text']],
            ['handoff', 'team', 'Qualified lead'],
        ], $replay);
        $this->assertStringContainsString('handed this conversation to the team', (string) $run['timeline'][8]['text'], 'the summary note the team got');
        $this->assertSame('Buy', $run['answers']['menu']);
    }

    public function test_viewers_see_runs_without_phone_numbers_when_they_cannot_see_contacts(): void
    {
        $this->liveFlow($this->graph('Buy or rent?'));
        $this->says('hi');
        $role = $this->tenantContext()->bypass(fn () => Role::query()->create([
            'tenant_id' => $this->tenant->id, 'key' => 'bot_viewer', 'name' => 'Bot viewer', 'is_system' => false, 'permissions' => ['chatbots.view'],
        ]));
        $viewer = $this->addMember($this->tenant, SystemRole::Viewer);
        $this->tenantContext()->bypass(fn () => $viewer->forceFill(['role_id' => $role->id])->save());
        $this->actingAsMember($viewer);

        $run = $this->getJson('/api/v1/chatbots/runs')->assertOk()->json('data.0');
        $this->assertNull($run['contact']['phone']);
        $this->assertSame('Sara Ahmed', $run['contact']['name']);
        $this->getJson("/api/v1/chatbots/runs/{$run['id']}")->assertOk()->assertJsonPath('data.contact.phone', null);
    }

    public function test_another_workspace_cannot_open_a_run(): void
    {
        $this->liveFlow($this->graph('Buy or rent?'));
        $this->says('hi');
        $id = $this->getJson('/api/v1/chatbots/runs')->json('data.0.id');

        $other = $this->createTenant();
        $this->subscribe($other, 'pro');
        $this->actingAsMember($this->addMember($other));
        $this->getJson("/api/v1/chatbots/runs/{$id}")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/v1/chatbots/runs')->assertOk()->json('data'));
    }
}
