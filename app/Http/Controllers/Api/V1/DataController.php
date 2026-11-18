<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Tenancy\WorkspaceDeletion;
use App\Domain\Audit\AuditLogger;
use App\Domain\Data\DataExports;
use App\Domain\Data\Jobs\BuildDataExport;
use App\Domain\Data\Models\DataExport;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RequestWorkspaceDeletionRequest;
use App\Http\Requests\Api\V1\StartDataExportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings › Data (batch 6, item 14), for the Owner: exports of the workspace's data, and deleting
 * the workspace after a waiting period. Message retention is a compliance setting (compliance/settings).
 */
final class DataController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    public function exports(): JsonResponse
    {
        $this->ownerOnly();
        $list = DataExport::query()->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->orderByDesc('created_at')->limit(30)->get();

        return response()->json([
            'data' => $list->map(fn (DataExport $e) => $this->present($e)),
            'meta' => ['kinds' => collect(DataExports::KINDS)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values()],
        ]);
    }

    public function startExport(StartDataExportRequest $request): JsonResponse
    {
        $this->ownerOnly();
        $membership = $this->context->membership();
        $export = DataExport::query()->create([
            'tenant_id' => $this->context->id(),
            'kind' => $request->validated('kind'),
            'requested_by_membership_id' => $membership?->id,
            'requested_by_name' => $membership !== null ? DB::table('users')->where('id', $membership->user_id)->value('name') : null,
        ]);
        $this->audit->record('data.export_started', $export, meta: ['kind' => $export->kind]);
        BuildDataExport::dispatch($this->context->id(), $export->id)->afterCommit();

        return response()->json(['data' => $this->present($export->refresh())], 202);
    }

    public function download(DataExport $export): StreamedResponse
    {
        $this->ownerOnly();
        abort_if($export->status !== 'ready' || $export->path === null || $export->expires_at?->isPast(), 404, 'This export is not ready or has expired.');
        $this->audit->record('data.export_downloaded', $export, meta: ['kind' => $export->kind]);
        $name = str_replace('_', '-', $export->kind).'-'.$export->created_at?->setTimezone($this->context->tenant()->timezone)->format('Y-m-d').'.csv';

        return Storage::disk((string) $export->disk)->download($export->path, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function deletion(WorkspaceDeletion $deletion): JsonResponse
    {
        $this->ownerOnly();

        return response()->json(['data' => $deletion->status($this->context->tenant())]);
    }

    public function requestDeletion(RequestWorkspaceDeletionRequest $request, WorkspaceDeletion $deletion): JsonResponse
    {
        $this->ownerOnly();
        $tenant = $this->context->tenant();
        $deletion->request($tenant, (string) $request->validated('confirm'));

        return response()->json(['data' => $deletion->status($tenant->refresh())], 202);
    }

    public function cancelDeletion(WorkspaceDeletion $deletion): Response
    {
        $this->ownerOnly();
        $deletion->cancel($this->context->tenant());

        return response()->noContent();
    }

    /** Exports and deleting the workspace are the Owner's alone. */
    private function ownerOnly(): void
    {
        $role = $this->context->membership()?->role;
        abort_unless($role !== null && $role->is_system && $role->key === 'owner', 403, 'Only the workspace Owner can do this.');
    }

    /** @return array<string, mixed> */
    private function present(DataExport $e): array
    {
        return [
            'id' => $e->id, 'kind' => $e->kind, 'status' => $e->status, 'rows' => $e->rows, 'error' => $e->error, 'requested_by' => $e->requested_by_name,
            'created_at' => $e->created_at?->toIso8601String(), 'finished_at' => $e->finished_at?->toIso8601String(), 'expires_at' => $e->expires_at?->toIso8601String(),
        ];
    }
}
