<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Compliance;

use App\Application\Messaging\SendMessage;
use App\Domain\Audit\AuditLogger;
use App\Domain\Compliance\ComplianceSettings;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Models\ConsentEvent;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MessageResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Compliance: the consent ledger (append-only proof of every opt-in / opt-out), opt-out
 * keywords and replies, retention, and policy checks. All tenant-scoped.
 */
final class ComplianceController extends Controller
{
    private const ACTIONS = ['opted_in', 'opted_out', 'marketing_opted_in', 'marketing_opted_out'];

    public function __construct(private readonly TenantContext $context, private readonly AuditLogger $audit) {}

    public function overview(): JsonResponse
    {
        $settings = ComplianceSettings::for($this->context->tenant());
        $states = Contact::query()->selectRaw('consent_state, count(*) AS total')->groupBy('consent_state')->toBase()->pluck('total', 'consent_state');
        $total = (int) $states->sum();
        $optedIn = (int) ($states['opted_in'] ?? 0);
        $since = now()->subDays(30);
        $recent = ConsentEvent::query()->where('created_at', '>=', $since)->selectRaw('action, count(*) AS total')->groupBy('action')->toBase()->pluck('total', 'action');
        $importedUnknown = Contact::query()->where('source', 'import')->where('consent_state', 'unknown')->count();
        $badNumbers = PhoneNumber::query()->whereIn('quality_rating', ['RED', 'YELLOW'])->get(['display_phone_number', 'verified_name', 'quality_rating']);
        $badTemplates = MessageTemplate::query()->whereIn('status', ['PAUSED', 'DISABLED'])->count();

        $checks = [
            ['key' => 'opt_out_keywords', 'status' => 'ok', 'title' => 'Customers can opt out at any time',
                'detail' => 'Replying '.strtoupper(implode(', ', array_slice($settings->optOutKeywords, 0, 4))).' unsubscribes a contact instantly.'],
            ['key' => 'opt_out_reply', 'status' => $settings->confirmOptOut ? 'ok' : 'warn', 'title' => 'Opt-outs are confirmed to the customer',
                'detail' => $settings->confirmOptOut ? 'A confirmation is sent when someone unsubscribes.' : 'Turn on the confirmation reply so customers know their request worked.'],
            ['key' => 'marketing_consent', 'status' => $total === 0 || $optedIn > 0 ? 'ok' : 'warn', 'title' => 'Marketing goes only to contacts who opted in',
                'detail' => $total === 0 ? 'No contacts yet.' : "{$optedIn} of {$total} contacts have given marketing consent. Campaigns with marketing templates skip everyone else."],
            ['key' => 'imported_without_consent', 'status' => $importedUnknown > 0 ? 'info' : 'ok', 'title' => 'Imported contacts have recorded consent',
                'detail' => $importedUnknown > 0 ? "{$importedUnknown} imported contacts have no recorded consent. Ask them to subscribe before sending marketing." : 'No imported contacts are missing consent.'],
            ['key' => 'number_quality', 'status' => $badNumbers->contains('quality_rating', 'RED') ? 'bad' : ($badNumbers->isNotEmpty() ? 'warn' : 'ok'), 'title' => 'Number quality is healthy',
                'detail' => $badNumbers->isEmpty() ? 'No number has a low quality rating.' : $badNumbers->map(fn (PhoneNumber $n) => ($n->verified_name ?? $n->display_phone_number).' is '.strtolower((string) $n->quality_rating))->implode('; ').'. Slow down marketing and review recent opt-outs.'],
            ['key' => 'templates', 'status' => $badTemplates > 0 ? 'warn' : 'ok', 'title' => 'No templates are paused by Meta',
                'detail' => $badTemplates > 0 ? "{$badTemplates} template(s) are paused or disabled for low quality. Review them in Templates." : 'No templates are paused or disabled.'],
            ['key' => 'retention', 'status' => $settings->retentionEnabled ? 'ok' : 'info', 'title' => 'A retention policy is set',
                'detail' => $settings->retentionEnabled ? "Messages are redacted after {$settings->messageRetentionDays} days and media deleted after {$settings->mediaRetentionDays} days." : 'Messages and media are kept indefinitely. Set a retention period if your privacy policy requires one.'],
        ];

        return response()->json(['data' => [
            'contacts' => ['total' => $total, 'opted_in' => $optedIn, 'unknown' => (int) ($states['unknown'] ?? 0), 'opted_out' => (int) ($states['opted_out'] ?? 0),
                'marketing_stopped_in_whatsapp' => Contact::query()->where('marketing_opted_out', true)->count()],
            'last_30_days' => ['opt_ins' => (int) ($recent['opted_in'] ?? 0), 'opt_outs' => (int) ($recent['opted_out'] ?? 0)],
            'checks' => $checks,
        ]]);
    }

    public function settings(): JsonResponse
    {
        return response()->json(['data' => ComplianceSettings::for($this->context->tenant())->toArray()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'opt_out_keywords' => ['sometimes', 'array', 'max:40'],
            'opt_out_keywords.*' => ['string', 'max:24'],
            'opt_in_keywords' => ['sometimes', 'array', 'max:40'],
            'opt_in_keywords.*' => ['string', 'max:24'],
            'confirm_opt_out' => ['sometimes', 'boolean'],
            'opt_out_reply' => ['sometimes', 'string', 'min:5', 'max:500'],
            'confirm_opt_in' => ['sometimes', 'boolean'],
            'opt_in_reply' => ['sometimes', 'string', 'min:5', 'max:500'],
            'consent_request_text' => ['sometimes', 'string', 'min:10', 'max:900'],
            'retention_enabled' => ['sometimes', 'boolean'],
            'message_retention_days' => ['sometimes', 'integer', 'min:'.ComplianceSettings::MIN_MESSAGE_DAYS, 'max:3650'],
            'media_retention_days' => ['sometimes', 'integer', 'min:'.ComplianceSettings::MIN_MEDIA_DAYS, 'max:3650'],
            'marketing_frequency_cap' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'quiet_hours_enabled' => ['sometimes', 'boolean'],
            'quiet_hours_start' => ['sometimes', 'date_format:H:i'],
            'quiet_hours_end' => ['sometimes', 'date_format:H:i'],
        ]);
        if (isset($data['opt_out_keywords'])) {
            $data['opt_out_keywords'] = ComplianceSettings::keywords($data['opt_out_keywords'], ComplianceSettings::LOCKED_OPT_OUT);
        }
        if (isset($data['opt_in_keywords'])) {
            $data['opt_in_keywords'] = ComplianceSettings::keywords($data['opt_in_keywords'], ComplianceSettings::LOCKED_OPT_IN);
        }
        // A word cannot mean both: opting out wins.
        if (isset($data['opt_in_keywords'])) {
            $out = $data['opt_out_keywords'] ?? ComplianceSettings::for($this->context->tenant())->optOutKeywords;
            $data['opt_in_keywords'] = array_values(array_diff($data['opt_in_keywords'], $out));
        }

        $tenant = $this->context->tenant();
        $all = $tenant->settings ?? [];
        $before = (array) ($all['compliance'] ?? []);
        $all['compliance'] = array_merge($before, $data);
        $tenant->forceFill(['settings' => $all])->save();

        $this->audit->record('compliance.settings_updated', $tenant, before: array_intersect_key($before, $data), after: $data);

        return response()->json(['data' => ComplianceSettings::for($tenant)->toArray()]);
    }

    public function consentEvents(Request $request): JsonResponse
    {
        $page = $this->ledger($request)->with(['contact' => fn ($q) => $q->withTrashed()])
            ->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate((int) $request->integer('per_page', 50));

        return response()->json([
            'data' => collect($page->items())->map(fn (ConsentEvent $e) => [
                'id' => $e->id,
                'action' => $e->action,
                'source' => $e->source,
                'detail' => $e->detail,
                'created_at' => $e->created_at?->toIso8601String(),
                'contact' => $e->contact !== null ? ['id' => $e->contact->id, 'display_name' => $e->contact->displayName(), 'phone' => $e->contact->wa_id !== null ? '+'.$e->contact->wa_id : null] : null,
            ]),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode()],
        ]);
    }

    /** The ledger as a file: the proof to hand to Meta or a regulator. */
    public function exportConsentEvents(Request $request): StreamedResponse
    {
        $query = $this->ledger($request)->with(['contact' => fn ($q) => $q->withTrashed()])->orderBy('created_at')->orderBy('id');
        $tenant = $this->context->tenant();
        $membership = $this->context->membership();
        $this->audit->record('compliance.ledger_exported', $tenant);

        return response()->streamDownload(fn () => $this->context->run($tenant, function () use ($query): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['time_utc', 'phone', 'name', 'event', 'source', 'detail'], ',', '"', '');
            $query->chunk(1000, function ($events) use ($out): void {
                /** @var ConsentEvent $e */
                foreach ($events as $e) {
                    $name = (string) ($e->contact->name ?? $e->contact->profile_name ?? '');
                    $detail = (string) $e->detail;
                    fputcsv($out, [
                        $e->created_at?->utc()->format('Y-m-d H:i:s'), $e->contact?->wa_id !== null ? '+'.$e->contact->wa_id : '',
                        preg_match('/^[=+\-@]/', $name) === 1 ? "'".$name : $name, $e->action, $e->source,
                        preg_match('/^[=+\-@]/', $detail) === 1 ? "'".$detail : $detail,
                    ], ',', '"', '');
                }
            });
            fclose($out);
        }, $membership), 'consent-ledger-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * In-chat opt-in: sends the consent question with Subscribe / No thanks buttons inside the
     * open 24-hour window. A tap on Subscribe is recorded in the ledger as an explicit opt-in.
     */
    public function requestConsent(Conversation $conversation, SendMessage $send): JsonResponse
    {
        $settings = ComplianceSettings::for($this->context->tenant());

        $message = $send->toConversation($conversation, [
            'type' => 'interactive',
            'body' => $settings->consentRequestText,
            'content' => [
                'type' => 'button',
                'body' => ['text' => $settings->consentRequestText],
                'action' => ['buttons' => [
                    ['type' => 'reply', 'reply' => ['id' => 'consent_opt_in', 'title' => 'Subscribe']],
                    ['type' => 'reply', 'reply' => ['id' => 'consent_decline', 'title' => 'No thanks']],
                ]],
            ],
        ], MessageOrigin::Agent, $this->context->membership());

        return MessageResource::make($message)->response()->setStatusCode(202);
    }

    /** @return Builder<ConsentEvent> */
    private function ledger(Request $request): Builder
    {
        $data = $request->validate([
            'action' => ['nullable', Rule::in(self::ACTIONS)],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $digits = preg_replace('/\D+/', '', (string) ($data['q'] ?? ''));

        return ConsentEvent::query()
            ->when($data['action'] ?? null, fn (Builder $q, string $action) => $q->where('action', $action))
            ->when($data['q'] ?? null, fn (Builder $q, string $term) => $q->whereIn('contact_id', Contact::query()->withTrashed()
                ->where(fn (Builder $w) => $w->where('name', 'ilike', "%{$term}%")->orWhere('profile_name', 'ilike', "%{$term}%")
                    ->when($digits !== '', fn (Builder $x) => $x->orWhere('wa_id', 'like', "%{$digits}%")))
                ->select('id')));
    }
}
