<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Crm;

use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditLogger;
use App\Domain\Crm\Models\ContactStageEvent;
use App\Domain\Crm\Models\CrmStage;
use App\Domain\Crm\Services\ContactStages;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ContactResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Contacts & CRM › pipeline stages, a contact's stage, and its stage history. */
final class StageController extends Controller
{
    private const MAX_STAGES = 30;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $stages = CrmStage::query()->when(! $request->boolean('include_archived'), fn ($q) => $q->active())
            ->orderBy('position')->orderBy('created_at')->get();
        $counts = Contact::query()->whereNotNull('stage_id')->groupBy('stage_id')->selectRaw('stage_id, count(*) AS n')->pluck('n', 'stage_id');

        return response()->json(['data' => $stages->map(fn (CrmStage $s) => $s->summary() + ['position' => $s->position, 'contacts' => (int) ($counts[$s->id] ?? 0)])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'color' => ['nullable', Rule::in(CrmStage::COLORS)],
        ]);
        if (CrmStage::query()->active()->count() >= self::MAX_STAGES) {
            throw ValidationException::withMessages(['name' => 'A pipeline can have up to '.self::MAX_STAGES.' stages.']);
        }
        $this->ensureUniqueName(trim($data['name']));

        $stage = CrmStage::query()->create([
            'name' => trim($data['name']),
            'color' => $data['color'] ?? 'grey',
            'position' => (int) CrmStage::query()->max('position') + 1,
        ]);
        $this->audit->record('crm_stage.created', $stage, after: $stage->summary());

        return response()->json(['data' => $stage->summary() + ['position' => $stage->position, 'contacts' => 0]], 201);
    }

    /** Creates the suggested pipeline (New, Contacted, Qualified, Won, Lost) for a workspace with no stages. */
    public function suggest(): JsonResponse
    {
        if (CrmStage::query()->active()->exists()) {
            throw ValidationException::withMessages(['stages' => 'This workspace already has stages.']);
        }
        DB::transaction(function () {
            foreach (CrmStage::SUGGESTED as $i => $s) {
                CrmStage::query()->create($s + ['position' => $i + 1]);
            }
        });
        $this->audit->record('crm_stage.suggested_created');

        return $this->index(request());
    }

    public function update(Request $request, CrmStage $stage): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:40'],
            'color' => ['sometimes', Rule::in(CrmStage::COLORS)],
        ]);
        abort_if($stage->archived_at !== null, 422, 'Archived stages cannot be changed.');
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            $this->ensureUniqueName($data['name'], $stage->id);
        }
        $before = $stage->summary();
        $stage->fill($data)->save();
        $this->audit->record('crm_stage.updated', $stage, before: $before, after: $stage->summary());

        return response()->json(['data' => $stage->summary() + ['position' => $stage->position]]);
    }

    /** New order: every active stage id, top to bottom. */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:'.self::MAX_STAGES], 'ids.*' => ['uuid']]);
        $active = CrmStage::query()->active()->pluck('id')->all();
        if (array_diff($active, $data['ids']) !== [] || array_diff($data['ids'], $active) !== []) {
            throw ValidationException::withMessages(['ids' => 'Send every active stage once.']);
        }
        DB::transaction(function () use ($data) {
            foreach (array_values($data['ids']) as $i => $id) {
                CrmStage::query()->whereKey($id)->update(['position' => $i + 1]);
            }
        });

        return $this->index($request);
    }

    /**
     * Removes a stage. Contacts on it must be moved first. A stage that appears in any contact's
     * history is archived (kept for reports), otherwise it is deleted.
     */
    public function destroy(CrmStage $stage): JsonResponse
    {
        if (Contact::query()->withTrashed()->where('stage_id', $stage->id)->exists()) {
            throw ValidationException::withMessages(['stage' => 'Move the contacts on this stage to another stage first.']);
        }
        $used = ContactStageEvent::query()->where(fn ($q) => $q->where('from_stage_id', $stage->id)->orWhere('to_stage_id', $stage->id))->exists();
        $used ? $stage->forceFill(['archived_at' => now()])->save() : $stage->delete();
        $this->audit->record($used ? 'crm_stage.archived' : 'crm_stage.deleted', $used ? $stage : null, before: $stage->summary());

        return response()->json(null, 204);
    }

    public function move(Request $request, Contact $contact, ContactStages $stages): ContactResource
    {
        $data = $request->validate([
            'stage_id' => ['present', 'nullable', 'uuid'],
            // Where the change is made: the chat sidebar and Growth Hub › Leads for anyone who may edit
            // contacts; the Contacts & CRM edit form for Owners and Admins only.
            'via' => ['sometimes', 'in:chat,leads,contact'],
        ]);
        $via = $data['via'] ?? 'chat';
        if ($via === 'contact') {
            $role = app(TenantContext::class)->membership()?->role?->systemRole();
            abort_unless(in_array($role, [SystemRole::Owner, SystemRole::Admin], true), 403, 'Only Owners and Admins change the stage from the contact record.');
        }
        $stages->move($contact, $data['stage_id']);

        return ContactResource::make($contact->load('stage'));
    }

    public function history(Contact $contact): JsonResponse
    {
        $events = ContactStageEvent::query()->where('contact_id', $contact->id)->orderByDesc('changed_at')->limit(200)->get();
        $ids = $events->flatMap(fn (ContactStageEvent $e) => [$e->from_stage_id, $e->to_stage_id])->filter()->unique();
        $names = CrmStage::query()->whereIn('id', $ids)->get()->keyBy('id');
        $members = DB::table('tenant_memberships')->join('users', 'users.id', '=', 'tenant_memberships.user_id')
            ->whereIn('tenant_memberships.id', $events->pluck('changed_by_membership_id')->filter()->unique())
            ->pluck('users.name', 'tenant_memberships.id');

        return response()->json(['data' => $events->map(fn (ContactStageEvent $e) => [
            'id' => $e->id,
            'from' => $e->from_stage_id ? $names[$e->from_stage_id]?->summary() : null,
            'to' => $e->to_stage_id ? $names[$e->to_stage_id]?->summary() : null,
            'changed_by' => $e->changed_by_membership_id ? ($members[$e->changed_by_membership_id] ?? 'A teammate') : null,
            'source' => $e->source,
            'changed_at' => $e->changed_at->toIso8601String(),
        ])]);
    }

    private function ensureUniqueName(string $name, ?string $except = null): void
    {
        $taken = CrmStage::query()->active()->whereRaw('lower(name) = lower(?)', [$name])
            ->when($except, fn ($q, $id) => $q->whereKeyNot($id))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => 'A stage with this name already exists.']);
        }
    }
}
