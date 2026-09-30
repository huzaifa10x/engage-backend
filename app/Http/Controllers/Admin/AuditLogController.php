<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Identity\Models\PlatformAdmin;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'actor_type' => ['nullable', Rule::in(['user', 'admin', 'system', 'api'])],
            'tenant_id' => ['nullable', 'uuid'],
        ]);

        $page = AuditLog::query()
            ->when($filters['action'] ?? null, fn ($q, $a) => $q->where('action', 'like', str_replace(['%', '_'], ['\%', '\_'], $a).'%'))
            ->when($filters['actor_type'] ?? null, fn ($q, $t) => $q->where('actor_type', $t))
            ->when($filters['tenant_id'] ?? null, fn ($q, $t) => $q->where('tenant_id', $t))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate(50)
            ->withQueryString();

        $rows = collect($page->items());
        $tenants = Tenant::withTrashed()->whereIn('id', $rows->pluck('tenant_id')->filter()->unique())->pluck('name', 'id');
        $admins = PlatformAdmin::query()->whereIn('id', $rows->where('actor_type', 'admin')->pluck('actor_id')->filter())->pluck('name', 'id');
        $users = User::query()->whereIn('id', $rows->where('actor_type', 'user')->pluck('actor_id')->filter())->pluck('email', 'id');

        return Inertia::render('audit/Index', [
            'entries' => $rows->map(fn (AuditLog $a) => [
                'id' => $a->id,
                'action' => $a->getAttribute('action'),
                'actor_type' => $a->getAttribute('actor_type'),
                'actor' => match ($a->getAttribute('actor_type')) {
                    'admin' => $admins[$a->getAttribute('actor_id')] ?? 'Admin',
                    'user' => $users[$a->getAttribute('actor_id')] ?? 'User',
                    default => 'System',
                },
                'tenant_id' => $a->tenant_id,
                'tenant' => $a->tenant_id ? ($tenants[$a->tenant_id] ?? null) : null,
                'entity_type' => $a->getAttribute('entity_type'),
                'entity_id' => $a->getAttribute('entity_id'),
                'before' => $a->getAttribute('before'),
                'after' => $a->getAttribute('after'),
                'meta' => $a->getAttribute('meta'),
                'ip' => $a->getAttribute('ip'),
                'request_id' => $a->getAttribute('request_id'),
                'created_at' => $a->getAttribute('created_at')?->toIso8601String(),
            ])->values(),
            'nextCursor' => $page->nextCursor()?->encode(),
            'prevCursor' => $page->previousCursor()?->encode(),
            'filters' => (object) $filters,
        ]);
    }
}
