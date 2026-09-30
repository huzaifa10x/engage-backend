<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Webhooks\Jobs\ProcessWebhookChange;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Raw inbound Meta webhooks: the first place to look when an onboarding or event goes missing. */
final class WebhookLogController extends Controller
{
    private const STATUSES = ['pending', 'processed', 'ignored', 'deferred', 'failed'];

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'field' => ['nullable', 'string', 'max:64'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'waba_id' => ['nullable', 'string', 'max:32'],
        ]);

        $since = now()->subDay();

        $rows = DB::table('webhook_inbound_log as l')
            ->leftJoin('tenants as t', 't.id', '=', 'l.tenant_id')
            ->where('l.received_at', '>=', now()->subDays(30))
            ->when($filters['field'] ?? null, fn ($q, $v) => $q->where('l.field', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('l.process_status', $v))
            ->when($filters['waba_id'] ?? null, fn ($q, $v) => $q->where('l.waba_id', $v))
            ->orderByDesc('l.received_at')->limit(100)
            ->get(['l.id', 'l.field', 'l.waba_id', 'l.phone_number_id', 'l.process_status', 'l.attempts', 'l.last_error',
                'l.received_at', 'l.processed_at', 'l.payload', 't.id as tenant_id', 't.name as tenant'])
            ->map(function ($r) {
                $payload = (string) $r->payload;
                $r->payload = strlen($payload) > 6000 ? mb_substr($payload, 0, 6000).' …(truncated)' : $payload;

                return $r;
            });

        $stats = DB::table('webhook_inbound_log')->where('received_at', '>=', $since)
            ->selectRaw('count(*) as total, '.implode(', ', array_map(fn ($s) => "count(*) filter (where process_status = '{$s}') as {$s}", self::STATUSES)))
            ->first();

        $fields = DB::table('webhook_inbound_log')->where('received_at', '>=', $since)
            ->groupBy('field')->orderByDesc(DB::raw('count(*)'))->limit(15)
            ->get(['field', DB::raw('count(*) as count')]);

        return Inertia::render('webhooks/Index', [
            'rows' => $rows,
            'stats' => array_map('intval', (array) $stats),
            'fields' => $fields,
            'filters' => (object) $filters,
            'configured' => [
                'app' => filled(config('engage.meta.app_id')) && filled(config('engage.meta.app_secret')),
                'verify_token' => filled(config('engage.meta.webhook_verify_token')),
                'callback_url' => url('/api/webhooks/meta'),
            ],
        ]);
    }

    public function replayFailed(): RedirectResponse
    {
        $rows = DB::table('webhook_inbound_log')->where('process_status', 'failed')
            ->where('received_at', '>=', now()->subDays(30))->orderBy('received_at')->limit(1000)->get(['id', 'received_at']);

        foreach ($rows as $row) {
            DB::table('webhook_inbound_log')->where('id', $row->id)->where('received_at', $row->received_at)->update(['process_status' => 'pending']);
            ProcessWebhookChange::dispatch($row->id, Carbon::parse($row->received_at)->toIso8601String());
        }

        return back()->with('success', "Re-queued {$rows->count()} failed webhook(s).");
    }
}
