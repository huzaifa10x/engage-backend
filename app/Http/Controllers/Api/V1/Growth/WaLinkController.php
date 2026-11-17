<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Growth;

use App\Application\Growth\ManageLinks;
use App\Domain\Growth\Links\WaLink;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Channels → links and QR codes: wa.me links with a source, and website buttons. */
final class WaLinkController extends Controller
{
    public function __construct(private readonly ManageLinks $links) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['phone_number_id' => ['nullable', 'uuid'], 'kind' => ['nullable', Rule::in([WaLink::KIND_LINK, WaLink::KIND_WEBSITE_BUTTON])]]);
        $list = WaLink::query()->with('phoneNumber')->whereNull('archived_at')
            ->when($data['phone_number_id'] ?? null, fn ($q, string $id) => $q->where('phone_number_id', $id))
            ->when($data['kind'] ?? null, fn ($q, string $k) => $q->where('kind', $k))
            ->orderByDesc('created_at')->limit(500)->get();
        $counts = DB::table('wa_link_chats')->whereIn('wa_link_id', $list->modelKeys())->groupBy('wa_link_id')
            ->selectRaw("wa_link_id, count(*) AS chats, count(*) FILTER (WHERE created_at >= now() - interval '30 days') AS chats_30d,
                count(*) FILTER (WHERE new_contact) AS new_contacts, max(created_at) AS last_chat_at")
            ->get()->keyBy('wa_link_id');

        return response()->json(['data' => $list->map(fn (WaLink $l) => $this->present($l, $counts->get($l->id)))]);
    }

    public function store(Request $request): JsonResponse
    {
        $link = $this->links->save(null, $this->input($request, true));

        return response()->json(['data' => $this->present($link->load('phoneNumber'))], 201);
    }

    public function update(Request $request, WaLink $waLink): JsonResponse
    {
        return response()->json(['data' => $this->present($this->links->save($waLink, $this->input($request, false))->load('phoneNumber'))]);
    }

    public function destroy(WaLink $waLink): JsonResponse
    {
        $this->links->archive($waLink);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function input(Request $request, bool $creating): array
    {
        return $request->validate([
            'phone_number_id' => [$creating ? 'required' : 'nullable', 'uuid'],
            'kind' => ['nullable', Rule::in([WaLink::KIND_LINK, WaLink::KIND_WEBSITE_BUTTON])],
            'name' => ['nullable', 'string', 'max:80'],
            'message' => ['nullable', 'string', 'max:400'],
            'asks_consent' => ['nullable', 'boolean'],
            'button' => ['nullable', 'array'],
            'button.position' => ['nullable', Rule::in(ManageLinks::POSITIONS)],
            'button.label' => ['nullable', 'string', 'max:30'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(WaLink $l, ?object $counts = null): array
    {
        return [
            'id' => $l->id,
            'kind' => $l->kind,
            'name' => $l->name,
            'code' => $l->code,
            'message' => $l->message,
            'asks_consent' => $l->asks_consent,
            'button' => $l->button,
            'phone_number' => $l->phoneNumber !== null ? ['id' => $l->phoneNumber->id, 'display' => $l->phoneNumber->verified_name ?? $l->phoneNumber->display_phone_number,
                'display_phone_number' => $l->phoneNumber->display_phone_number] : null,
            'prefilled_text' => $l->prefilledText(),
            'url' => $l->url(),
            'snippet' => $l->kind === WaLink::KIND_WEBSITE_BUTTON ? ManageLinks::snippet($l) : null,
            'chats' => (int) ($counts->chats ?? 0),
            'chats_30d' => (int) ($counts->chats_30d ?? 0),
            'new_contacts' => (int) ($counts->new_contacts ?? 0),
            'last_chat_at' => isset($counts->last_chat_at) ? Carbon::parse((string) $counts->last_chat_at)->toIso8601String() : null,
            'created_at' => $l->created_at?->toIso8601String(),
        ];
    }
}
