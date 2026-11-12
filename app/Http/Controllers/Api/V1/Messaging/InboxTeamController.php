<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Domain\Access\Permission;
use App\Domain\Audit\AuditLogger;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\InboxTeam;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Inbox teams: named groups of teammates a conversation (or a chatbot handover) can be given to.
 * Everyone with the inbox sees the list (for filters); team.manage changes it.
 */
final class InboxTeamController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $teams = InboxTeam::query()->orderBy('name')->get();
        $members = DB::table('inbox_team_members')->whereIn('team_id', $teams->pluck('id'))->get()->groupBy('team_id');
        $open = Conversation::query()->whereIn('assigned_team_id', $teams->pluck('id'))->where('status', 'open')
            ->selectRaw('assigned_team_id, count(*) AS total')->groupBy('assigned_team_id')->toBase()->pluck('total', 'assigned_team_id');

        return response()->json(['data' => $teams->map(fn (InboxTeam $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'member_ids' => ($members[$t->id] ?? collect())->pluck('membership_id')->values(),
            'open_conversations' => (int) ($open[$t->id] ?? 0),
        ])->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->context->authorize(Permission::TeamManage);
        $data = $this->validated($request);
        $team = DB::transaction(function () use ($data): InboxTeam {
            $team = InboxTeam::query()->create(['name' => $data['name']]);
            $team->syncMembers($data['member_ids']);

            return $team;
        });
        $this->audit->record('inbox_team.created', $team, after: $data);

        return $this->index();
    }

    public function update(Request $request, InboxTeam $team): JsonResponse
    {
        $this->context->authorize(Permission::TeamManage);
        $data = $this->validated($request, $team);
        DB::transaction(function () use ($team, $data): void {
            $team->forceFill(['name' => $data['name']])->save();
            $team->syncMembers($data['member_ids']);
        });
        $this->audit->record('inbox_team.updated', $team, after: $data);

        return $this->index();
    }

    public function destroy(InboxTeam $team): Response
    {
        $this->context->authorize(Permission::TeamManage);
        DB::transaction(function () use ($team): void {
            // Conversations keep their assignee; they just no longer belong to a team.
            Conversation::query()->where('assigned_team_id', $team->id)->update(['assigned_team_id' => null]);
            $team->delete();
        });
        $this->audit->record('inbox_team.deleted', $team, before: ['name' => $team->name]);

        return response()->noContent();
    }

    /** @return array{name: string, member_ids: list<string>} */
    private function validated(Request $request, ?InboxTeam $team = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('inbox_teams', 'name')->where('tenant_id', $this->context->id())->ignore($team?->id)],
            'member_ids' => ['present', 'array', 'max:200'],
            'member_ids.*' => ['uuid'],
        ]);
        $ids = array_values(array_unique($data['member_ids']));
        $active = TenantMembership::query()->active()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (string) $id)->all();
        if (count($active) !== count($ids)) {
            throw ValidationException::withMessages(['member_ids' => 'Choose active members of this workspace.']);
        }

        return ['name' => trim($data['name']), 'member_ids' => $active];
    }
}
