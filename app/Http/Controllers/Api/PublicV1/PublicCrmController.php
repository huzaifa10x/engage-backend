<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\PublicV1;

use App\Application\Messaging\ManageContacts;
use App\Domain\Chatbots\Models\ChatbotFlow;
use App\Domain\Chatbots\Services\Chatbots;
use App\Domain\Crm\Models\CrmStage;
use App\Domain\Crm\Services\EnquiryStages;
use App\Domain\Developer\Services\PublicPayload;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Timeline\Enquiries;
use App\Domain\Timeline\Enquiry;
use App\Domain\Timeline\Timeline;
use App\Domain\Timeline\TimelineEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\PublicV1\ContactTagsRequest;
use App\Http\Requests\PublicV1\ListEnquiriesRequest;
use App\Http\Requests\PublicV1\ListTimelineRequest;
use App\Http\Requests\PublicV1\MoveEnquiryStageRequest;
use App\Http\Requests\PublicV1\StartEnquiryRequest;
use App\Http\Requests\PublicV1\StartFlowRequest;
use Illuminate\Http\JsonResponse;

/**
 * Public API v1 (batch 6, item 6): contact tags, enquiries and their stages, the contact timeline,
 * and starting a published flow. The workspace is the API key's; every change is in the Audit log
 * under the key's name (AuditApiKeyWrites).
 */
final class PublicCrmController extends Controller
{
    // ── Tags ───────────────────────────────────────────────────────────────────────────────

    public function addTags(ContactTagsRequest $request, string $id, ManageContacts $contacts): JsonResponse
    {
        $contact = Contact::query()->findOrFail($id);
        $tags = array_values(array_unique([...$contact->tags, ...$request->tags()]));

        return response()->json(['data' => PublicPayload::contact($contacts->update($contact, ['tags' => $tags], partial: true))]);
    }

    public function removeTags(ContactTagsRequest $request, string $id, ManageContacts $contacts): JsonResponse
    {
        $contact = Contact::query()->findOrFail($id);
        $remove = array_map('mb_strtolower', $request->tags());
        $tags = array_values(array_filter($contact->tags, fn (string $t) => ! in_array(mb_strtolower($t), $remove, true)));

        return response()->json(['data' => PublicPayload::contact($contacts->update($contact, ['tags' => $tags], partial: true))]);
    }

    // ── Enquiries and stages ───────────────────────────────────────────────────────────────

    public function stages(): JsonResponse
    {
        return response()->json(['data' => CrmStage::query()->active()->orderBy('position')->get()->map(fn (CrmStage $s) => PublicPayload::stage($s))]);
    }

    public function enquiries(ListEnquiriesRequest $request): JsonResponse
    {
        $data = $request->validated();
        $page = Enquiry::query()
            ->when($data['contact_id'] ?? null, fn ($q, $id) => $q->where('contact_id', $id))
            ->when($data['stage_id'] ?? null, fn ($q, $id) => $q->where('stage_id', $id))
            ->orderByDesc('started_at')->orderByDesc('id')->cursorPaginate((int) ($data['limit'] ?? 25));
        $stages = CrmStage::query()->whereIn('id', collect($page->items())->pluck('stage_id')->filter()->unique())->get()->keyBy('id');

        return response()->json([
            'data' => collect($page->items())->map(fn (Enquiry $e) => PublicPayload::enquiry($e, $e->stage_id !== null ? $stages->get($e->stage_id) : null)),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /** The contact's current enquiry, started now if they have none (201) — never a second open one. */
    public function startEnquiry(StartEnquiryRequest $request, Enquiries $enquiries): JsonResponse
    {
        $contact = Contact::query()->findOrFail($request->validated('contact_id'));
        $had = $contact->getAttribute('current_enquiry_id') !== null;
        $enquiry = $enquiries->current($contact, Timeline::SYSTEM);

        return response()->json(['data' => PublicPayload::enquiry($enquiry, $enquiry->stage_id !== null ? CrmStage::query()->find($enquiry->stage_id) : null)], $had ? 200 : 201);
    }

    public function enquiry(string $id): JsonResponse
    {
        $enquiry = Enquiry::query()->findOrFail($id);

        return response()->json(['data' => PublicPayload::enquiry($enquiry, $enquiry->stage_id !== null ? CrmStage::query()->find($enquiry->stage_id) : null)]);
    }

    /**
     * Moves an enquiry to a stage. "version" is the one last read: if someone changed the stage since,
     * the answer is 409 and nothing changes. Won needs a verified payment, unless the key may approve
     * Won, in which case "reason" carries the reference (an invoice or receipt number).
     */
    public function moveStage(MoveEnquiryStageRequest $request, string $id, EnquiryStages $stages): JsonResponse
    {
        $enquiry = Enquiry::query()->findOrFail($id);
        $contact = Contact::query()->findOrFail($enquiry->contact_id);
        $data = $request->validated();
        $stages->move($contact, $data['stage_id'], 'api', (int) $data['version'], $data['reason'] ?? null, enquiry: $enquiry);

        return $this->enquiry($id);
    }

    public function timeline(ListTimelineRequest $request): JsonResponse
    {
        $data = $request->validated();
        $page = TimelineEvent::query()
            ->when($data['contact_id'] ?? null, fn ($q, $id) => $q->where('contact_id', $id))
            ->when($data['enquiry_id'] ?? null, fn ($q, $id) => $q->where('enquiry_id', $id))
            ->when($data['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->orderByDesc('occurred_at')->orderByDesc('id')->cursorPaginate((int) ($data['limit'] ?? 50));

        TimelineEvent::markRemovedMessages($page->items());

        return response()->json(['data' => collect($page->items())->map(fn (TimelineEvent $e) => PublicPayload::timelineEvent($e)), 'next_cursor' => $page->nextCursor()?->encode()]);
    }

    // ── Flows ──────────────────────────────────────────────────────────────────────────────

    /** Published, switched-on flows that can be started for a contact. */
    public function flows(): JsonResponse
    {
        $flows = ChatbotFlow::query()->where('kind', 'bot')->where('is_active', true)->whereNotNull('published_version')->whereNull('archived_at')->orderBy('name')->get();

        return response()->json(['data' => $flows->map(fn (ChatbotFlow $f) => ['id' => $f->id, 'name' => $f->name, 'phone_number_id' => $f->phone_number_id, 'version' => $f->published_version])]);
    }

    /**
     * Starts a published flow for a contact on the flow's number. 202 "started"; otherwise 200 with
     * why it did not: the contact opted out, a teammate handles the chat, a flow is already running…
     */
    public function startFlow(StartFlowRequest $request, string $id, Chatbots $chatbots): JsonResponse
    {
        $flow = ChatbotFlow::query()->where('kind', 'bot')->findOrFail($id);
        $contact = Contact::query()->findOrFail($request->validated('contact_id'));
        $result = $chatbots->startFor($flow, $contact, $request->vars(), 'api');

        return response()->json(['data' => ['result' => $result]], $result === 'started' ? 202 : 200);
    }
}
