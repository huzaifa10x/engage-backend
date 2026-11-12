<?php

declare(strict_types=1);

namespace Tests\Feature\Chatbots;

use App\Domain\Access\SystemRole;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationNote;
use Tests\Concerns\BuildsChatbots;
use Tests\TestCase;

/**
 * Inbox teams and team handover (D6): a handover step gives the chat to a team of the shared inbox;
 * the inbox's own picker chooses the team's least busy member who can reply on the number.
 */
final class ChatbotTeamHandoverTest extends TestCase
{
    use BuildsChatbots;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootChatbots();
    }

    /** @param list<string> $memberIds */
    private function team(string $name, array $memberIds): string
    {
        $this->actingAsMember($this->owner);
        $teams = $this->postJson('/api/v1/inbox/teams', ['name' => $name, 'member_ids' => $memberIds])->assertOk()->json('data');

        return collect($teams)->firstWhere('name', $name)['id'];
    }

    /** @return array<string, mixed> */
    private function graph(?string $teamId): array
    {
        return ['nodes' => [
            $this->node('start', 'start', ['any_message' => true], ['next' => 'team']),
            $this->node('team', 'handoff', ['text' => 'Our leasing team will reply.', 'assign' => 'team', 'team_id' => $teamId, 'outcome' => 'Renter']),
        ]];
    }

    public function test_teams_are_managed_by_team_managers_and_listed_for_the_inbox(): void
    {
        $agent = $this->addMember($this->tenant, SystemRole::Agent);
        $id = $this->team('Leasing', [$agent->id]);
        $this->postJson('/api/v1/inbox/teams', ['name' => 'Leasing', 'member_ids' => []])->assertUnprocessable();

        $this->actingAsMember($agent);
        $this->getJson('/api/v1/inbox/teams')->assertOk()->assertJsonPath('data.0.name', 'Leasing')->assertJsonPath('data.0.member_ids.0', $agent->id);
        $this->postJson('/api/v1/inbox/teams', ['name' => 'Sales', 'member_ids' => []])->assertForbidden();
        $this->putJson("/api/v1/inbox/teams/{$id}", ['name' => 'Sales', 'member_ids' => []])->assertForbidden();

        $other = $this->createTenant();
        $this->actingAsMember($this->addMember($other));
        $this->assertSame([], $this->getJson('/api/v1/inbox/teams')->assertOk()->json('data'), 'teams are per workspace');
        $this->deleteJson("/api/v1/inbox/teams/{$id}")->assertNotFound();
    }

    public function test_a_handover_to_a_team_assigns_its_least_busy_member_and_files_the_chat_under_the_team(): void
    {
        $sara = $this->addMember($this->tenant, SystemRole::Admin);
        $omar = $this->addMember($this->tenant, SystemRole::Admin);
        $teamId = $this->team('Leasing', [$sara->id, $omar->id]);
        // Omar already has an open chat: Sara is the least busy.
        $this->inTenant(fn () => Conversation::query()->create([
            'phone_number_id' => $this->number->id, 'contact_id' => Contact::query()->create(['wa_id' => '971500000099', 'name' => 'Busy'])->id,
            'status' => 'open', 'assigned_membership_id' => $omar->id,
        ]));
        $this->liveFlow($this->graph($teamId));

        $this->says('hi');
        $conversation = $this->inTenant(fn () => Conversation::query()->whereHas('contact', fn ($q) => $q->where('wa_id', '971501234567'))->sole());
        $this->assertSame($teamId, $conversation->assigned_team_id);
        $this->assertSame($sara->id, $conversation->assigned_membership_id);
        $this->assertSame('handed_off', $this->botSession()->status);
        $this->assertStringContainsString('(Leasing)', (string) $this->inTenant(fn () => ConversationNote::query()->where('conversation_id', $conversation->id)->latest('created_at')->value('body')));

        // The shared inbox filters by team.
        $this->actingAsMember($this->owner);
        $ids = collect($this->getJson("/api/v1/conversations?team={$teamId}")->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$conversation->id], $ids);
        $this->assertSame($teamId, $this->getJson("/api/v1/conversations?team={$teamId}")->json('data.0.assigned_team_id'));
    }

    public function test_a_team_nobody_can_answer_for_keeps_the_chat_with_the_team_unassigned(): void
    {
        // An agent without access to this number cannot be given its chats.
        $agent = $this->addMember($this->tenant, SystemRole::Agent);
        $teamId = $this->team('Night shift', [$agent->id]);
        $this->liveFlow($this->graph($teamId));

        $this->says('hi');
        $conversation = $this->inTenant(fn () => Conversation::query()->sole());
        $this->assertSame($teamId, $conversation->assigned_team_id);
        $this->assertNull($conversation->assigned_membership_id);
    }

    public function test_publishing_needs_a_team_and_a_deleted_team_needs_attention(): void
    {
        $this->actingAsMember($this->owner);
        $id = $this->postJson('/api/v1/chatbots', ['name' => 'Bot', 'phone_number_id' => $this->number->id, 'template' => 'blank'])->json('data.id');
        $this->patchJson("/api/v1/chatbots/{$id}", ['draft' => $this->graph(null)])->assertOk();
        $issues = $this->postJson("/api/v1/chatbots/{$id}/publish")->assertUnprocessable()->json('error.details.issues');
        $this->assertContains('no_team', array_column($issues, 'code'));

        $teamId = $this->team('Leasing', [$this->owner->id]);
        $this->patchJson("/api/v1/chatbots/{$id}", ['draft' => $this->graph($teamId)])->assertOk();
        $this->postJson("/api/v1/chatbots/{$id}/publish")->assertOk();
        $this->deleteJson("/api/v1/inbox/teams/{$teamId}")->assertNoContent();
        $this->assertContains('missing_team', array_column($this->getJson("/api/v1/chatbots/{$id}")->json('data.issues'), 'code'));
    }

    public function test_the_test_chat_names_the_team_without_assigning_anyone(): void
    {
        $teamId = $this->team('Leasing', [$this->owner->id]);
        $this->actingAsMember($this->owner);
        $id = $this->postJson('/api/v1/chatbots', ['name' => 'Bot', 'phone_number_id' => $this->number->id, 'template' => 'blank'])->json('data.id');
        $this->patchJson("/api/v1/chatbots/{$id}", ['draft' => $this->graph($teamId)])->assertOk();
        $t = $this->postJson("/api/v1/chatbots/{$id}/test", [])->assertOk()->json('data.transcript');
        $this->assertStringContainsString('Leasing team', collect($t)->pluck('text')->filter()->implode("\n"));
        $this->assertSame(0, $this->inTenant(fn () => Conversation::query()->count()));
    }
}
