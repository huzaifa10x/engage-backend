<?php

declare(strict_types=1);

namespace Tests\Feature\Messaging;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\Models\Role;
use App\Domain\Access\SystemRole;
use App\Domain\Identity\Models\User;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Notifications\TenantInvitationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithWhatsapp;
use Tests\TestCase;

/** Assigning a chat to a teammate depends on the teammate's access to the chat's number. */
final class AssignmentTest extends TestCase
{
    use InteractsWithWhatsapp;

    private Tenant $tenant;

    private PhoneNumber $number;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureMeta();
        $this->tenant = $this->createTenant();
        $this->subscribe($this->tenant, 'pro');
        $this->number = $this->connectNumber($this->tenant);
        $this->postWebhook($this->webhookBody('messages', $this->inboundValue()))->assertOk();
    }

    private function conversation(): Conversation
    {
        return $this->tenantContext()->run($this->tenant, fn () => Conversation::query()->sole());
    }

    public function test_assigning_to_a_teammate_without_the_number_explains_it_and_lists_who_can_take_it(): void
    {
        $agent = $this->addMember($this->tenant, SystemRole::Agent, User::factory()->create(['name' => 'Filza Ali']));
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Owner));
        $id = $this->conversation()->id;

        $this->patchJson("/api/v1/conversations/{$id}", ['assigned_membership_id' => $agent->id])
            ->assertStatus(422)
            ->assertJsonPath('error.details.fields.assigned_membership_id.0', "Filza Ali doesn't have access to {$this->number->display_phone_number}. Give them this number in Team & Roles, or assign someone else.");

        $list = collect($this->getJson("/api/v1/conversations/{$id}/assignees")->assertOk()->json('data'))->keyBy('id');
        $this->assertFalse($list[$agent->id]['can_access']);

        $this->tenantContext()->run($this->tenant, fn () => app(NumberAccess::class)->setGrants($agent, [$this->number->id]));
        $this->assertTrue(collect($this->getJson("/api/v1/conversations/{$id}/assignees")->json('data'))->keyBy('id')[$agent->id]['can_access']);
        $this->patchJson("/api/v1/conversations/{$id}", ['assigned_membership_id' => $agent->id])->assertOk()->assertJsonPath('data.assigned_membership_id', $agent->id);
    }

    public function test_an_invited_agent_gets_the_numbers_chosen_in_the_invitation(): void
    {
        Notification::fake();
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Owner))
            ->postJson('/api/v1/team/invitations', ['email' => 'agent@example.com', 'role_id' => Role::system(SystemRole::Agent)->id, 'phone_number_ids' => [$this->number->id]])
            ->assertCreated()->assertJsonPath('data.phone_number_ids', [$this->number->id]);
        $token = '';
        Notification::assertSentOnDemand(TenantInvitationNotification::class, function (TenantInvitationNotification $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        Sanctum::actingAs(User::factory()->create(['email' => 'agent@example.com']));
        $membershipId = $this->postJson("/api/v1/invitations/{$token}/accept")->assertOk()->json('data.id');

        $this->assertSame([$this->number->id], $this->tenantContext()->bypass(fn () => DB::table('user_number_access')->where('membership_id', $membershipId)->pluck('phone_number_id')->all()));
    }

    public function test_an_invitation_cannot_grant_another_workspaces_number(): void
    {
        $other = $this->createTenant();
        $foreign = $this->connectNumber($other, '999', '888', '+971 50 000 0000');
        $this->actingAsMember($this->addMember($this->tenant, SystemRole::Owner))
            ->postJson('/api/v1/team/invitations', ['email' => 'x@example.com', 'role_id' => Role::system(SystemRole::Agent)->id, 'phone_number_ids' => [$foreign->id]])
            ->assertStatus(422);
    }
}
