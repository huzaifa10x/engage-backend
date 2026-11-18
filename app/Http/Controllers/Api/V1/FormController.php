<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Forms\ManageForms;
use App\Domain\Access\PhoneVisibility;
use App\Domain\Forms\Form;
use App\Domain\Forms\FormDefinition;
use App\Domain\Forms\FormSubmission;
use App\Domain\Forms\FormVersion;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Support\Dates\DateText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Chatbots & Flows › Bot settings › WhatsApp forms. */
final class FormController extends Controller
{
    public function __construct(private readonly ManageForms $forms, private readonly TenantContext $context) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['waba_account_id' => ['nullable', 'uuid']]);
        $forms = Form::query()->whereNull('archived_at')->when($data['waba_account_id'] ?? null, fn ($q, string $id) => $q->where('waba_account_id', $id))
            ->orderBy('name')->get();
        $counts = DB::table('form_submissions')->whereIn('form_id', $forms->modelKeys())->groupBy('form_id')->selectRaw('form_id, count(*) AS total, max(created_at) AS last_at')->get()->keyBy('form_id');

        return response()->json(['data' => $forms->map(fn (Form $f) => $this->present($f, false) + [
            'submissions' => (int) ($counts[$f->id]->total ?? 0),
        ])]);
    }

    public function show(Form $form): JsonResponse
    {
        return response()->json(['data' => $this->present($form, true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'waba_account_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:80'],
            'kind' => ['nullable', Rule::in(['booking', 'lead', 'custom'])],
            'definition' => ['nullable', 'array'],
        ]);
        $form = $this->forms->create($data['waba_account_id'], $data['name'], $data['kind'] ?? 'custom', $data['definition'] ?? null);

        return response()->json(['data' => $this->present($form, true)], 201);
    }

    public function update(Request $request, Form $form): JsonResponse
    {
        $data = $request->validate(['name' => ['nullable', 'string', 'max:80'], 'definition' => ['required', 'array']]);

        return response()->json(['data' => $this->present($this->forms->update($form, $data['name'] ?? null, $data['definition']), true)]);
    }

    public function publish(Form $form): JsonResponse
    {
        return response()->json(['data' => $this->present($this->forms->publish($form), true)]);
    }

    public function refresh(Form $form): JsonResponse
    {
        return response()->json(['data' => $this->present($this->forms->refresh($form), true)]);
    }

    public function destroy(Form $form): JsonResponse
    {
        $this->forms->archive($form);

        return response()->json(null, 204);
    }

    public function submissions(Request $request, Form $form): JsonResponse
    {
        $page = FormSubmission::query()->where('form_id', $form->id)->orderByDesc('created_at')->cursorPaginate(min(100, max(1, (int) $request->integer('per_page', 50))));
        $versions = FormVersion::query()->where('form_id', $form->id)->pluck('version', 'id');
        $contacts = Contact::query()->withTrashed()->whereIn('id', collect($page->items())->pluck('contact_id'))->get()->keyBy('id');
        $tz = $this->context->tenant()->timezone ?: 'Asia/Dubai';

        return response()->json([
            'data' => collect($page->items())->map(fn (FormSubmission $s) => [
                'id' => $s->id,
                'version' => $versions[$s->form_version_id] ?? null,
                'contact' => ($c = $contacts[$s->contact_id] ?? null) !== null ? ['id' => $c->id, 'display_name' => $c->displayName(), 'phone' => app(PhoneVisibility::class)->show($c->wa_id)] : null,
                'conversation_id' => $s->conversation_id,
                'answers' => $s->saved,
                'consent' => $s->consent,
                // In the workspace's date format and time zone (item 1).
                'received_at' => $s->created_at !== null ? DateText::shortAt($s->created_at, $this->context->tenant()->date_format, $tz) : null,
            ]),
            'meta' => ['next_cursor' => $page->nextCursor()?->encode()],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Form $form, bool $full): array
    {
        /** @var ?FormVersion $current */
        $current = FormVersion::query()->find($form->current_version_id);
        $live = $form->liveVersion();
        $out = [
            'id' => $form->id,
            'name' => $form->name,
            'kind' => $form->kind,
            'waba_account_id' => $form->waba_account_id,
            'version' => $current?->version,
            // Plain words for Meta's status of the version being edited, and the one customers get now.
            'status' => $current?->meta_status,
            'status_text' => ManageForms::STATUS_TEXT[$current->meta_status ?? 'NOT_CREATED'] ?? $current?->meta_status,
            'live_version' => $live?->version,
            'errors' => array_map(fn ($e) => (string) (((array) $e)['message'] ?? ((array) $e)['error'] ?? 'Unknown problem'), (array) ($current->meta_errors ?? [])),
            'checked_at' => $current?->checked_at?->toIso8601String(),
            'updated_at' => $form->updated_at?->toIso8601String(),
        ];
        if (! $full) {
            return $out;
        }

        return $out + [
            'definition' => $current?->definition,
            'editing_creates_version' => $current?->locked() ?? false,
            'flow_json' => $current !== null ? FormDefinition::flowJson($current->definition) : null,
            'versions' => FormVersion::query()->where('form_id', $form->id)->orderByDesc('version')->get()->map(fn (FormVersion $v) => [
                'version' => $v->version, 'status' => $v->meta_status, 'status_text' => ManageForms::STATUS_TEXT[$v->meta_status] ?? $v->meta_status,
                'meta_flow_id' => $v->meta_flow_id, 'published_at' => $v->published_at?->toIso8601String(),
            ]),
        ];
    }
}
