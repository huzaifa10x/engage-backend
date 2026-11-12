<?php

declare(strict_types=1);

namespace Tests\Feature\Chatbots;

use App\Domain\Chatbots\Models\ChatbotDraftRevision;
use App\Domain\Chatbots\Models\ChatbotFlow;
use App\Domain\Chatbots\Models\ChatbotFlowVersion;
use App\Domain\Chatbots\Services\Chatbots;
use App\Domain\Messaging\Models\Conversation;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsChatbots;
use Tests\TestCase;

/**
 * Engine level (existing flows keep their behaviour on deploy) and conflict-safe draft editing (D1).
 */
final class ChatbotEngineLevelAndDraftsTest extends TestCase
{
    use BuildsChatbots;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootChatbots();
    }

    /** @return array<string, mixed> */
    private function dateFlow(): array
    {
        return ['nodes' => [
            $this->node('start', 'start', ['any_message' => true], ['next' => 'day']),
            $this->node('day', 'question', ['text' => 'Which day?', 'validate' => 'future_date', 'save_to' => 'var:day', 'retries' => 5], ['next' => 'ok']),
            $this->node('ok', 'message', ['text' => 'Booked for {{var.day}}.'], ['next' => 'team']),
            $this->node('team', 'handoff', ['text' => 'A person will reply.']),
        ]];
    }

    /** What the migration does to every flow and version that existed before level 2. */
    private function makeLegacy(ChatbotFlow $flow): void
    {
        $this->inTenant(function () use ($flow) {
            DB::statement("UPDATE chatbot_flows SET settings = jsonb_set(COALESCE(settings, '{}'::jsonb), '{engine_level}', '1'::jsonb) WHERE id = ?", [$flow->id]);
            DB::statement("UPDATE chatbot_flow_versions SET settings = jsonb_set(COALESCE(settings, '{}'::jsonb), '{engine_level}', '1'::jsonb) WHERE flow_id = ?", [$flow->id]);
        });
        $this->app->forgetInstance(Chatbots::class); // published versions are cached per request
    }

    public function test_new_flows_get_level_2_and_a_level_1_flow_keeps_the_old_behaviour(): void
    {
        $this->travelTo(now()->setTimezone('Asia/Dubai')->setDate(2026, 11, 12)->setTime(13, 0));
        $flow = $this->liveFlow($this->dateFlow());
        $this->assertSame(2, $this->getJson("/api/v1/chatbots/{$flow->id}")->json('data.engine_level'));

        $this->makeLegacy($flow);
        $this->says('hi');
        $this->says('31/04/2027');

        // Level 1 read 31/04 as 1 May (strtotime) and showed the raw date, as before the change.
        $this->assertSame(['Which day?', 'Booked for 2027-05-01.', 'A person will reply.'], $this->texts());
        $this->assertSame(1, $this->inTenant(fn () => ChatbotFlowVersion::query()->where('flow_id', $flow->id)->sole())->settings['engine_level']);
    }

    public function test_level_2_refuses_the_impossible_date_and_shows_it_readably(): void
    {
        $this->travelTo(now()->setTimezone('Asia/Dubai')->setDate(2026, 11, 12)->setTime(13, 0));
        $this->liveFlow($this->dateFlow());
        $this->says('hi');
        $this->says('31/04/2027');
        $this->says('18/11');

        $this->assertSame(['Which day?', 'Sorry, that date doesn’t exist. Please check the day and month.', 'Which day?', 'Booked for Wed 18 Nov 2026.', 'A person will reply.'], $this->texts());
    }

    public function test_a_level_1_flow_stops_silently_when_the_window_is_closed_as_before(): void
    {
        $flow = $this->liveFlow(['nodes' => [
            $this->node('start', 'start', ['any_message' => true], ['next' => 'wait']),
            $this->node('wait', 'delay', ['minutes' => 5], ['next' => 'follow']),
            $this->node('follow', 'message', ['text' => 'Still interested?'], ['next' => 'team']),
            $this->node('team', 'handoff', ['text' => 'A person will reply.']),
        ]]);
        $this->makeLegacy($flow);
        $this->says('hi');
        $this->inTenant(fn () => Conversation::query()->update(['window_expires_at' => now()->subMinute()]));
        $this->travel(6)->minutes();
        $this->artisan('engage:chatbots:tick')->assertSuccessful();

        $this->assertSame('stopped', $this->botSession()->status, 'level 1: stopped (level 2 hands over)');
        $this->assertSame('window_closed', $this->botSession()->ended_reason);
    }

    public function test_saving_without_the_level_keeps_it_and_upgrading_is_explicit_and_audited(): void
    {
        $flow = $this->liveFlow($this->dateFlow());
        $this->makeLegacy($flow);

        // An editor that doesn't know the field sends the settings without it: the level stays 1.
        $settings = $this->getJson("/api/v1/chatbots/{$flow->id}")->json('data.settings');
        unset($settings['engine_level']);
        $this->patchJson("/api/v1/chatbots/{$flow->id}", ['settings' => $settings])->assertOk()->assertJsonPath('data.engine_level', 1);
        $this->postJson("/api/v1/chatbots/{$flow->id}/publish")->assertOk()->assertJsonPath('data.published_engine_level', 1);

        // Upgrading is sent on purpose, recorded, and reaches customers only after publishing.
        $this->patchJson("/api/v1/chatbots/{$flow->id}", ['settings' => ['engine_level' => 2] + $settings])->assertOk()
            ->assertJsonPath('data.engine_level', 2)->assertJsonPath('data.published_engine_level', 1);
        $this->assertTrue($this->inTenant(fn () => DB::table('audit_log')->where('action', 'chatbot.engine_level')->exists()));
        $this->postJson("/api/v1/chatbots/{$flow->id}/publish")->assertOk()->assertJsonPath('data.published_engine_level', 2);
    }

    public function test_two_editors_saving_from_the_same_revision_conflict_instead_of_overwriting(): void
    {
        $flow = $this->liveFlow($this->dateFlow());
        $base = $this->getJson("/api/v1/chatbots/{$flow->id}")->json('data');
        $draftA = $base['draft'];
        $draftA['nodes'][1]['config']['text'] = 'Which day suits you?';
        $draftB = $base['draft'];
        $draftB['nodes'][2]['config']['text'] = 'See you on {{var.day}}.';

        $first = $this->patchJson("/api/v1/chatbots/{$flow->id}", ['draft' => $draftA, 'base_revision' => $base['draft_revision']])->assertOk();
        $this->assertSame($base['draft_revision'] + 1, $first->json('data.draft_revision'));

        $second = $this->patchJson("/api/v1/chatbots/{$flow->id}", ['draft' => $draftB, 'base_revision' => $base['draft_revision']])->assertStatus(409);
        $this->assertSame('draft_conflict', $second->json('error.code'));
        $this->assertSame($base['draft_revision'] + 1, $second->json('error.details.revision'));
        $this->assertSame('Which day suits you?', $second->json('error.details.draft.nodes.1.config.text'), 'the stored draft comes back to merge with');
        $this->assertNotNull($second->json('error.details.edited_by'));

        // Nothing was overwritten; saving the merged draft on the new revision works.
        $this->assertSame('Which day suits you?', $this->inTenant(fn () => ChatbotFlow::query()->findOrFail($flow->id))->draft['nodes'][1]['config']['text']);
        $merged = $second->json('error.details.draft');
        $merged['nodes'][2]['config']['text'] = 'See you on {{var.day}}.';
        $this->patchJson("/api/v1/chatbots/{$flow->id}", ['draft' => $merged, 'base_revision' => $second->json('error.details.revision')])->assertOk();

        $revisions = $this->getJson("/api/v1/chatbots/{$flow->id}/revisions")->assertOk()->json('data');
        $this->assertSame($base['draft_revision'] + 2, $revisions[0]['revision']);
        $this->getJson("/api/v1/chatbots/{$flow->id}/revisions/{$revisions[1]['revision']}")->assertOk()->assertJsonPath('data.draft.nodes.1.config.text', 'Which day suits you?');
    }

    public function test_publishing_an_older_revision_than_the_stored_draft_is_refused(): void
    {
        $flow = $this->liveFlow($this->dateFlow());
        $seen = $this->getJson("/api/v1/chatbots/{$flow->id}")->json('data');
        $draft = $seen['draft'];
        $draft['nodes'][2]['config']['text'] = 'Changed by a teammate';
        $this->patchJson("/api/v1/chatbots/{$flow->id}", ['draft' => $draft])->assertOk();

        $this->postJson("/api/v1/chatbots/{$flow->id}/publish", ['revision' => $seen['draft_revision']])->assertStatus(409)->assertJsonPath('error.code', 'draft_conflict');
        $this->assertSame(1, $this->inTenant(fn () => ChatbotFlowVersion::query()->where('flow_id', $flow->id)->count()));
        $this->postJson("/api/v1/chatbots/{$flow->id}/publish", ['revision' => $seen['draft_revision'] + 1])->assertOk();
    }

    public function test_only_the_last_50_drafts_are_kept_and_revisions_are_consecutive(): void
    {
        $flow = $this->liveFlow($this->dateFlow());
        $draft = $this->getJson("/api/v1/chatbots/{$flow->id}")->json('data.draft');
        foreach (range(1, 55) as $i) {
            $draft['nodes'][2]['config']['text'] = "Version {$i} {{var.day}}";
            $this->patchJson("/api/v1/chatbots/{$flow->id}", ['draft' => $draft])->assertOk();
        }
        $rows = $this->inTenant(fn () => ChatbotDraftRevision::query()->where('flow_id', $flow->id)->orderBy('revision')->pluck('revision')->all());
        $current = $this->inTenant(fn () => ChatbotFlow::query()->findOrFail($flow->id))->draft_revision;
        $this->assertCount(ChatbotDraftRevision::KEEP, $rows);
        $this->assertSame($current, end($rows), 'revisions are consecutive and the newest is the stored draft');
        $this->assertSame(range($current - ChatbotDraftRevision::KEEP + 1, $current), $rows);
    }
}
