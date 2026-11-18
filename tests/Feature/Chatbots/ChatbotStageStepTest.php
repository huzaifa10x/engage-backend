<?php

declare(strict_types=1);

namespace Tests\Feature\Chatbots;

use App\Domain\Crm\Models\ContactStageEvent;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsChatbots;
use Tests\TestCase;

/** Batch 6, item 4: a flow step can move the lead to a stage; it can never mark Won on its own. */
final class ChatbotStageStepTest extends TestCase
{
    use BuildsChatbots;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootChatbots();
        $this->actingAsMember($this->owner);
        $this->postJson('/api/v1/crm/stages/suggested')->assertOk();
    }

    private function stageId(string $name): string
    {
        return (string) $this->inTenant(fn () => DB::table('crm_stages')->where('name', $name)->value('id'));
    }

    public function test_a_tag_step_moves_the_lead_to_a_stage_and_the_timeline_shows_the_flow_did_it(): void
    {
        $this->liveFlow(['nodes' => [
            $this->node('start', 'start', ['any_message' => true], ['next' => 'stage']),
            $this->node('stage', 'tag', ['add' => ['Rental lead'], 'stage_id' => $this->stageId('Qualified')], ['next' => 'team']),
            $this->node('team', 'handoff', ['text' => 'A person will reply.']),
        ]]);
        $this->says('hi');

        $this->assertSame($this->stageId('Qualified'), $this->contact()->getAttribute('stage_id'));
        $event = $this->inTenant(fn () => ContactStageEvent::query()->sole());
        $this->assertSame(['flow', null, 1], [$event->source, $event->changed_by_membership_id, $event->version]);
        $stage = collect($this->getJson("/api/v1/contacts/{$this->contact()->id}/timeline?group=stages")->assertOk()->json('data'))->sole();
        $this->assertSame(['system', 'Qualified', 'flow'], [$stage['actor'], $stage['data']['to'], $stage['data']['reason']]);
    }

    public function test_a_flow_cannot_be_published_with_a_step_that_marks_won(): void
    {
        $id = $this->postJson('/api/v1/chatbots', ['name' => 'Bot', 'phone_number_id' => $this->number->id, 'template' => 'blank'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/chatbots/{$id}", ['draft' => ['nodes' => [
            $this->node('start', 'start', ['any_message' => true], ['next' => 'stage']),
            $this->node('stage', 'tag', ['stage_id' => $this->stageId('Won')], ['next' => 'team']),
            $this->node('team', 'handoff', ['text' => 'A person will reply.']),
        ]]])->assertOk();
        $issues = collect($this->postJson("/api/v1/chatbots/{$id}/publish")->assertUnprocessable()->json('error.details.issues'));
        $this->assertTrue($issues->contains(fn ($i) => ($i['code'] ?? null) === 'stage_won'), (string) json_encode($issues));
    }
}
