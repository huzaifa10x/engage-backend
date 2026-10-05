<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Crm;

use App\Application\Crm\ContactImporter;
use App\Application\Crm\TagCatalog;
use App\Domain\Audit\AuditLogger;
use App\Domain\Crm\Models\ContactField;
use App\Domain\Crm\Models\ContactTag;
use App\Domain\Crm\Models\Segment;
use App\Domain\Crm\Services\SegmentQuery;
use App\Domain\Messaging\Enums\ConsentState;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Plans\Entitlements\EntitlementService;
use App\Domain\Plans\FeatureKey;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Contacts & CRM: tags, custom fields, segments (saved rules), CSV import and export. */
final class CrmController extends Controller
{
    public function __construct(
        private readonly SegmentQuery $segments,
        private readonly EntitlementService $entitlements,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    // ── Tags ───────────────────────────────────────────────────────────────────────────────

    public function tags(): JsonResponse
    {
        $counts = collect(DB::select('SELECT tag, count(*) AS contacts FROM contacts, unnest(tags) AS tag WHERE tenant_id = ? AND deleted_at IS NULL GROUP BY tag', [$this->context->id()]))
            ->mapWithKeys(fn (object $row) => [(string) $row->tag => (int) $row->contacts]);

        return response()->json(['data' => ContactTag::query()->orderBy('name')->get()->map(fn (ContactTag $t) => [
            'id' => $t->id, 'name' => $t->name, 'contacts' => $counts[$t->name] ?? 0,
        ])]);
    }

    public function storeTag(Request $request, TagCatalog $tags): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:40']]);
        $name = $tags->ensure([$data['name']], 'name')[0] ?? throw ValidationException::withMessages(['name' => 'Enter a tag name.']);

        return response()->json(['data' => ['name' => $name]], 201);
    }

    /** Deletes the tag and removes it from every contact. */
    public function destroyTag(ContactTag $tag): JsonResponse
    {
        Contact::query()->withTrashed()->whereRaw('tags @> ARRAY[?]::text[]', [$tag->name])
            ->update(['tags' => DB::raw('array_remove(tags, '.DB::getPdo()->quote($tag->name).')')]);
        $tag->delete();
        $this->audit->record('tag.deleted', null, meta: ['name' => $tag->name]);

        return response()->json(null, 204);
    }

    /** Add / remove tags on many contacts at once (table selection). */
    public function bulkTag(Request $request, TagCatalog $tags): JsonResponse
    {
        $data = $request->validate([
            'contact_ids' => ['required', 'array', 'min:1', 'max:500'],
            'contact_ids.*' => ['uuid'],
            'add' => ['nullable', 'array', 'max:20'],
            'remove' => ['nullable', 'array', 'max:20'],
        ]);
        $add = $tags->ensure((array) ($data['add'] ?? []), 'add');
        $remove = array_map(fn ($t) => trim((string) $t), (array) ($data['remove'] ?? []));

        $changed = 0;
        Contact::query()->whereIn('id', $data['contact_ids'])->get()->each(function (Contact $contact) use ($add, $remove, &$changed): void {
            $contact->tags = array_values(array_diff(array_unique(array_merge($contact->tags, $add)), $remove));
            $changed += $contact->isDirty('tags') ? (int) $contact->save() : 0;
        });

        return response()->json(['data' => ['updated' => $changed]]);
    }

    // ── Custom fields ──────────────────────────────────────────────────────────────────────

    public function fields(): JsonResponse
    {
        return response()->json(['data' => ContactField::query()->orderBy('label')->get(['id', 'key', 'label', 'type'])]);
    }

    public function storeField(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'type' => ['nullable', Rule::in(['text', 'number', 'date'])],
        ]);
        $key = trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($data['label'])), '_');
        if ($key === '' || in_array($key, ['phone', 'name', 'email', 'tags', 'opted_in'], true)) {
            throw ValidationException::withMessages(['label' => 'Choose a different field name.']);
        }
        if (ContactField::query()->where('key', $key)->exists()) {
            throw ValidationException::withMessages(['label' => 'A field with this name already exists.']);
        }

        $field = $this->entitlements->withinLimit($this->context->tenant(), FeatureKey::CustomFields, 1,
            fn () => ContactField::query()->create(['key' => mb_substr($key, 0, 40), 'label' => $data['label'], 'type' => $data['type'] ?? 'text']));

        return response()->json(['data' => $field->only(['id', 'key', 'label', 'type'])], 201);
    }

    /** Removes the definition; values already stored on contacts are kept. */
    public function destroyField(ContactField $field): JsonResponse
    {
        $field->delete();

        return response()->json(null, 204);
    }

    // ── Segments ───────────────────────────────────────────────────────────────────────────

    public function segmentsIndex(): JsonResponse
    {
        return response()->json(['data' => Segment::query()->orderBy('name')->get()->map(fn (Segment $s) => $this->segment($s, withCounts: true))]);
    }

    public function storeSegment(Request $request): JsonResponse
    {
        $data = $this->segmentInput($request);
        if (Segment::query()->where('name', $data['name'])->exists()) {
            throw ValidationException::withMessages(['name' => 'A segment with this name already exists.']);
        }

        $segment = $this->entitlements->withinLimit($this->context->tenant(), FeatureKey::SavedSegments, 1,
            fn () => Segment::query()->create($data + ['created_by_membership_id' => $this->context->membership()?->id]));
        $this->audit->record('segment.created', null, meta: ['name' => $segment->name]);

        return response()->json(['data' => $this->segment($segment, withCounts: true)], 201);
    }

    public function updateSegment(Request $request, Segment $segment): JsonResponse
    {
        $data = $this->segmentInput($request);
        if (Segment::query()->where('name', $data['name'])->whereKeyNot($segment->id)->exists()) {
            throw ValidationException::withMessages(['name' => 'A segment with this name already exists.']);
        }
        $segment->fill($data)->save();

        return response()->json(['data' => $this->segment($segment, withCounts: true)]);
    }

    public function destroySegment(Segment $segment): JsonResponse
    {
        $segment->delete();

        return response()->json(null, 204);
    }

    /** Live "who fits / who may be messaged" for a rule being edited or an audience being chosen. */
    public function previewSegment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'segment_id' => ['nullable', 'uuid'],
            'match' => ['nullable', Rule::in(['all', 'any'])],
            'rules' => ['nullable', 'array', 'max:20'],
        ]);
        if (isset($data['segment_id'])) {
            $segment = Segment::query()->findOrFail($data['segment_id']);
            [$match, $rules] = [$segment->match, $segment->rules];
        } else {
            [$match, $rules] = [$data['match'] ?? 'all', $this->segments->normalize((array) ($data['rules'] ?? []))];
        }

        return response()->json(['data' => $this->counts($match, $rules)]);
    }

    // ── Import / export ────────────────────────────────────────────────────────────────────

    public function import(Request $request, ContactImporter $importer): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:40'],
            'opted_in' => ['nullable', 'boolean'],
        ]);

        return response()->json(['data' => $importer->import($data['file'], array_values((array) ($data['tags'] ?? [])), (bool) ($data['opted_in'] ?? false))]);
    }

    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate(['segment_id' => ['nullable', 'uuid'], 'tag' => ['nullable', 'string', 'max:40']]);
        $segment = isset($data['segment_id']) ? Segment::query()->findOrFail($data['segment_id']) : null;
        $keys = ContactField::query()->orderBy('key')->pluck('key')->all();

        $query = Contact::query()
            ->when($segment, fn (Builder $q) => $this->segments->apply($q, $segment->match, $segment->rules))
            ->when($data['tag'] ?? null, fn (Builder $q, string $tag) => $q->whereRaw('tags @> ARRAY[?]::text[]', [$tag]))
            ->orderBy('created_at');
        $this->audit->record('contacts.exported', null, meta: ['segment' => $segment?->name]);

        // The body is streamed after the request middleware has finished, so the workspace context
        // is re-entered for the duration of the stream.
        $tenant = $this->context->tenant();
        $membership = $this->context->membership();

        return response()->streamDownload(fn () => $this->context->run($tenant, function () use ($query, $keys): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['phone', 'name', 'email', 'tags', 'consent', ...$keys], ',', '"', '');
            $query->chunk(1000, function ($contacts) use ($out, $keys): void {
                foreach ($contacts as $c) {
                    // Leading = + - @ would be run as a formula by spreadsheet apps.
                    $safe = fn (?string $v) => $v !== null && preg_match('/^[=+\-@]/', $v) === 1 && ! preg_match('/^\+\d+$/', $v) ? "'".$v : (string) $v;
                    fputcsv($out, [
                        $c->wa_id !== null ? '+'.$c->wa_id : '', $safe($c->name ?? $c->profile_name), $safe($c->email), implode(';', $c->tags), $c->consent_state->value,
                        ...array_map(fn (string $k) => $safe(isset($c->custom_fields[$k]) ? (string) $c->custom_fields[$k] : null), $keys),
                    ], ',', '"', '');
                }
            });
            fclose($out);
        }, $membership), 'contacts-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────────────────

    /** @return array{name: string, match: string, rules: list<array<string, mixed>>} */
    private function segmentInput(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'match' => ['required', Rule::in(['all', 'any'])],
            'rules' => ['required', 'array', 'min:1', 'max:20'],
        ]);

        return ['name' => trim($data['name']), 'match' => $data['match'], 'rules' => $this->segments->normalize($data['rules'])];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     * @return array{matched: int, eligible_marketing: int, eligible_utility: int}
     */
    private function counts(string $match, array $rules): array
    {
        $base = fn () => $this->segments->apply(Contact::query(), $match, $rules);
        $reachable = fn (Builder $q) => $q->where('consent_state', '!=', ConsentState::OptedOut->value)
            ->where(fn (Builder $w) => $w->whereNotNull('wa_id')->orWhereNotNull('bsuid'));

        return [
            'matched' => $base()->count(),
            'eligible_marketing' => $reachable($base())->where('consent_state', ConsentState::OptedIn->value)->where('marketing_opted_out', false)->count(),
            'eligible_utility' => $reachable($base())->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function segment(Segment $segment, bool $withCounts = false): array
    {
        return [
            'id' => $segment->id,
            'name' => $segment->name,
            'match' => $segment->match,
            'rules' => $segment->rules,
            'updated_at' => $segment->updated_at?->toIso8601String(),
        ] + ($withCounts ? ['counts' => $this->counts($segment->match, $segment->rules)] : []);
    }
}
