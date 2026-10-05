<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Templates;

use App\Application\Templates\ManageTemplates;
use App\Application\Templates\TemplateSync;
use App\Domain\Templates\Enums\TemplateStatus;
use App\Domain\Templates\Exceptions\TemplateException;
use App\Domain\Templates\Jobs\SyncMessageTemplates;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\WhatsApp\Enums\WabaStatus;
use App\Domain\WhatsApp\Exceptions\WhatsappException;
use App\Domain\WhatsApp\Models\WabaAccount;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TemplateResource;
use App\Infrastructure\Meta\MetaApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Templates of the workspace's connected WhatsApp Business Accounts. Every query is tenant
 * scoped (BelongsToTenant + row level security), so a client only ever sees its own.
 */
final class TemplateController extends Controller
{
    private const CATEGORIES = ['MARKETING', 'UTILITY'];

    /** Lists stored templates; a stale account is refreshed from Meta in the background. */
    public function index(Request $request, TemplateSync $sync): JsonResponse
    {
        $data = $request->validate([
            'waba_account_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string', 'max:32'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $accounts = WabaAccount::query()->where('status', WabaStatus::Connected)
            ->when($data['waba_account_id'] ?? null, fn ($query, string $id) => $query->whereKey($id))
            ->get();

        // Templates waiting for Meta's review are re-checked often, so a decision shows up within
        // seconds even if a webhook is missed; everything else is refreshed every couple of minutes.
        $reviewing = MessageTemplate::query()->whereIn('waba_account_id', $accounts->modelKeys())
            ->where('status', TemplateStatus::PENDING)->pluck('waba_account_id')->unique()->all();

        $synced = [];
        foreach ($accounts as $account) {
            $at = $sync->lastSyncedAt($account);
            $synced[$account->id] = $at;
            $maxAge = in_array($account->id, $reviewing, true) ? 20 : 120;
            if ($at === null || Carbon::parse($at)->lt(now()->subSeconds($maxAge))) {
                try {
                    SyncMessageTemplates::dispatch($account->id);
                } catch (Throwable $e) {
                    // Listing must work even when Meta or the queue is unavailable.
                    Log::warning('Could not start a template sync.', ['waba_id' => $account->waba_id, 'error' => $e->getMessage()]);
                }
            }
        }

        $templates = MessageTemplate::query()
            ->whereIn('waba_account_id', $accounts->modelKeys())
            ->when($data['status'] ?? null, fn ($query, string $status) => $query->where('status', strtoupper($status)))
            ->when($data['q'] ?? null, fn ($query, string $term) => $query->where('name', 'ilike', '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%'))
            ->orderBy('name')->orderBy('language')
            ->limit(1000)
            ->get();

        return response()->json([
            'data' => TemplateResource::collection($templates),
            'meta' => ['last_synced_at' => (object) $synced],
        ]);
    }

    public function show(MessageTemplate $template): TemplateResource
    {
        return TemplateResource::make($template);
    }

    /** Creates the template on Meta and submits it for review. */
    public function store(Request $request, ManageTemplates $templates): JsonResponse
    {
        $data = $request->validate([
            'waba_account_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:512', 'regex:/^[a-z0-9_]+$/'],
            'language' => ['required', 'string', 'max:16', 'regex:/^[a-z]{2,3}(_[A-Z]{2})?$/'],
        ] + $this->contentRules(), ['name.regex' => 'Use lowercase letters, numbers and underscores only, e.g. order_update.']);

        $waba = WabaAccount::query()->findOrFail($data['waba_account_id']);

        return TemplateResource::make($templates->create($waba, $data))->response()->setStatusCode(201);
    }

    /** Edits content (and category unless approved) on Meta; the template is reviewed again. */
    public function update(Request $request, MessageTemplate $template, ManageTemplates $templates): TemplateResource
    {
        return TemplateResource::make($templates->update($template, $request->validate($this->contentRules())));
    }

    public function destroy(MessageTemplate $template, ManageTemplates $templates): JsonResponse
    {
        $templates->delete($template);

        return response()->json(null, 204);
    }

    /** "Sync now": read everything from Meta immediately and report what happened. */
    public function sync(Request $request, TemplateSync $sync): JsonResponse
    {
        $data = $request->validate(['waba_account_id' => ['nullable', 'uuid']]);

        $accounts = WabaAccount::query()->where('status', WabaStatus::Connected)
            ->when($data['waba_account_id'] ?? null, fn ($query, string $id) => $query->whereKey($id))
            ->get();

        $totals = ['synced' => 0, 'removed' => 0, 'accounts' => $accounts->count()];
        foreach ($accounts as $account) {
            try {
                $result = $sync->syncWaba($account);
            } catch (MetaApiException $e) {
                throw TemplateException::meta($e, 'sync');
            } catch (WhatsappException) {
                throw TemplateException::accountUnavailable();
            }
            $totals['synced'] += $result['synced'];
            $totals['removed'] += $result['removed'];
        }

        return response()->json(['data' => $totals]);
    }

    /** @return array<string, list<mixed>> */
    private function contentRules(): array
    {
        return [
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'header' => ['nullable', 'array'],
            'header.format' => ['nullable', Rule::in(['TEXT', 'IMAGE', 'VIDEO', 'DOCUMENT'])],
            'header.media_id' => ['nullable', 'uuid'],
            'header.text' => ['nullable', 'string', 'max:60'],
            'header.example' => ['nullable', 'string', 'max:60'],
            'body' => ['required', 'string', 'max:1024'],
            'body_examples' => ['nullable', 'array', 'max:50'],
            'body_examples.*' => ['nullable', 'string', 'max:200'],
            'footer' => ['nullable', 'string', 'max:60'],
            'buttons' => ['nullable', 'array', 'max:10'],
            'buttons.*.type' => ['required', Rule::in(['QUICK_REPLY', 'URL', 'PHONE_NUMBER'])],
            'buttons.*.text' => ['required', 'string', 'max:25'],
            'buttons.*.url' => ['required_if:buttons.*.type,URL', 'nullable', 'string', 'max:2000', 'starts_with:https://,http://'],
            'buttons.*.example' => ['nullable', 'string', 'max:2000'],
            'buttons.*.phone_number' => ['required_if:buttons.*.type,PHONE_NUMBER', 'nullable', 'string', 'max:20'],
        ];
    }
}
