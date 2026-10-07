<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\PublicV1;

use App\Application\Messaging\ManageContacts;
use App\Application\Messaging\SendMessage;
use App\Domain\Developer\Models\ApiKey;
use App\Domain\Developer\Services\ApiMedia;
use App\Domain\Developer\Services\PublicPayload;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\ConsentService;
use App\Domain\Templates\Models\MessageTemplate;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Enums\PhoneNumberStatus;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Public API v1, for customers' own systems (authenticated by API key, see AuthenticateApiKey).
 *
 * Every endpoint works inside the key's workspace only. Sending goes through exactly the same
 * service as the portal, so the 24-hour window, opt-outs, template rules, the per-number
 * messages-per-second limit and the plan's rate limit all apply without being re-implemented here.
 */
final class PublicApiController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function me(Request $request): JsonResponse
    {
        /** @var ApiKey $key */
        $key = $request->attributes->get('api_key');
        $tenant = $this->context->tenant();

        return response()->json(['data' => [
            'workspace' => ['id' => $tenant->id, 'name' => $tenant->name],
            'api_key' => ['name' => $key->name, 'prefix' => $key->prefix, 'scopes' => $key->scopes, 'expires_at' => $key->expires_at?->toIso8601String()],
        ]]);
    }

    public function phoneNumbers(): JsonResponse
    {
        return response()->json(['data' => PhoneNumber::query()->orderBy('created_at')->get()->map(fn (PhoneNumber $n) => [
            'id' => $n->id, 'phone' => $n->display_phone_number, 'name' => $n->verified_name, 'status' => $n->status->value, 'quality' => $n->quality_rating,
        ])]);
    }

    public function templates(Request $request): JsonResponse
    {
        $status = $request->string('status')->toString();
        $templates = MessageTemplate::query()->when($status !== '', fn ($q) => $q->where('status', strtoupper($status)))->orderBy('name')->limit(500)->get();

        return response()->json(['data' => $templates->map(fn (MessageTemplate $t) => [
            'id' => $t->id, 'name' => $t->name, 'language' => $t->language, 'category' => $t->category, 'status' => $t->status,
        ])]);
    }

    // ── Messages ───────────────────────────────────────────────────────────────────────────

    /**
     * Send a text or a media file (inside the customer's 24-hour window) or an approved template (any time).
     * Accepted (202) means queued for WhatsApp; follow the result with GET /messages/{id} or the
     * message.* webhook events. Send an Idempotency-Key header to make a retry safe.
     */
    public function sendMessage(Request $request, SendMessage $send, ApiMedia $media): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required_without:contact_id', 'nullable', 'string', 'max:32'],
            'contact_id' => ['required_without:to', 'nullable', 'uuid'],
            'from' => ['nullable', 'uuid'],
            'type' => ['required', 'in:text,template,image,video,audio,document'],
            'text' => ['required_if:type,text', 'nullable', 'string', 'max:4096'],
            // Media: a file uploaded earlier (POST /media) or a public https address we download from.
            'media_id' => ['nullable', 'uuid', 'prohibits:media_url'],
            'media_url' => ['nullable', 'string', 'max:2000', 'url'],
            'caption' => ['nullable', 'string', 'max:1024'],
            'filename' => ['nullable', 'string', 'max:240'],
            'preview_url' => ['nullable', 'boolean'],
            'template' => ['required_if:type,template', 'nullable', 'array'],
            'template.name' => ['required_if:type,template', 'nullable', 'string', 'max:512'],
            'template.language' => ['required_if:type,template', 'nullable', 'string', 'max:15'],
            'template.variables' => ['nullable', 'array'],
            'template.variables.header' => ['nullable', 'array', 'max:1'],
            'template.variables.header.*' => ['nullable', 'string', 'max:60'],
            'template.variables.body' => ['nullable', 'array', 'max:50'],
            'template.variables.body.*' => ['nullable', 'string', 'max:1024'],
            'template.variables.buttons' => ['nullable', 'array', 'max:10'],
            'template.variables.buttons.*' => ['nullable', 'string', 'max:2000'],
        ], ['from.uuid' => 'Use the id of one of your phone numbers (GET /phone-numbers).']);

        $number = $this->senderNumber($data['from'] ?? null);

        if (! empty($data['contact_id'])) {
            $contact = Contact::query()->findOrFail($data['contact_id']);
        } else {
            $waId = Contact::normalizePhone((string) $data['to']) ?? throw ValidationException::withMessages(['to' => 'Use the international format, for example +971501234567.']);
            $contact = Contact::query()->withTrashed()->where('wa_id', $waId)->first() ?? Contact::query()->create(['wa_id' => $waId, 'source' => 'api']);
            if ($contact->trashed()) {
                $contact->restore();
            }
        }

        $payload = match ($data['type']) {
            'text' => ['type' => 'text', 'body' => $data['text'], 'content' => ['preview_url' => (bool) ($data['preview_url'] ?? false)]],
            'template' => ['type' => 'template', 'template' => $data['template']],
            default => $this->mediaPayload($data, $media),
        };

        $key = $request->header('Idempotency-Key');
        $message = $send->toContact($number, $contact, $payload, MessageOrigin::Api, null, is_string($key) && $key !== '' ? mb_substr($key, 0, 128) : null);

        return response()->json(['data' => PublicPayload::message($message->load(['contact', 'media']))], 202);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{type: string, body: ?string, media_id: string, content: array<string, mixed>}
     */
    private function mediaPayload(array $data, ApiMedia $media): array
    {
        if (empty($data['media_id']) && empty($data['media_url'])) {
            throw ValidationException::withMessages(['media_id' => 'Give the file to send: "media_id" from POST /media, or "media_url" with a public https address.']);
        }
        if ($data['type'] === 'audio' && ! empty($data['caption'])) {
            throw ValidationException::withMessages(['caption' => 'WhatsApp does not show captions on audio messages.']);
        }
        $id = $data['media_id'] ?? $media->fromUrl((string) $data['media_url'], $data['filename'] ?? null)->id;

        return ['type' => $data['type'], 'body' => $data['caption'] ?? null, 'media_id' => $id, 'content' => array_filter(['filename' => $data['filename'] ?? null])];
    }

    /** Upload a file once, then send it to any number of people by its id. */
    public function uploadMedia(Request $request, ApiMedia $media): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:'.(100 * 1024)]]);

        return response()->json(['data' => ApiMedia::describe($media->fromUpload($request->file('file')))], 201);
    }

    public function message(string $id): JsonResponse
    {
        return response()->json(['data' => PublicPayload::message(Message::query()->with(['contact', 'media'])->findOrFail($id))]);
    }

    public function conversations(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', 'in:open,closed'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'string']]);
        $page = Conversation::query()->with('contact')->whereNotNull('last_message_at')
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('last_message_at')->orderByDesc('id')->cursorPaginate((int) ($data['limit'] ?? 25));

        return response()->json(['data' => collect($page->items())->map(fn (Conversation $c) => PublicPayload::conversation($c)), 'next_cursor' => $page->nextCursor()?->encode()]);
    }

    public function conversationMessages(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'string']]);
        $conversation = Conversation::query()->findOrFail($id);
        $page = Message::query()->with(['contact', 'media'])->where('conversation_id', $conversation->id)->orderByDesc('occurred_at')->orderByDesc('id')->cursorPaginate((int) ($data['limit'] ?? 50));

        return response()->json(['data' => collect($page->items())->map(fn (Message $m) => PublicPayload::message($m)), 'next_cursor' => $page->nextCursor()?->encode()]);
    }

    // ── Contacts ───────────────────────────────────────────────────────────────────────────

    public function contacts(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['nullable', 'string', 'max:32'], 'tag' => ['nullable', 'string', 'max:40'], 'limit' => ['nullable', 'integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'string']]);
        $waId = isset($data['phone']) ? Contact::normalizePhone($data['phone']) : null;
        $page = Contact::query()
            ->when(isset($data['phone']), fn ($q) => $q->where('wa_id', $waId ?? '-'))
            ->when($data['tag'] ?? null, fn ($q, $tag) => $q->whereJsonContains('tags', $tag))
            ->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate((int) ($data['limit'] ?? 25));

        return response()->json(['data' => collect($page->items())->map(fn (Contact $c) => PublicPayload::contact($c)), 'next_cursor' => $page->nextCursor()?->encode()]);
    }

    public function contact(string $id): JsonResponse
    {
        return response()->json(['data' => PublicPayload::contact(Contact::query()->findOrFail($id))]);
    }

    public function createContact(Request $request, ManageContacts $contacts): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'attributes' => ['nullable', 'array', 'max:50'],
            'tags' => ['nullable', 'array', 'max:50'],
            'tags.*' => ['string', 'max:40'],
            'opted_in' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => PublicPayload::contact($contacts->create($data)->refresh())], 201);
    }

    public function updateContact(Request $request, string $id, ManageContacts $contacts): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            'attributes' => ['sometimes', 'nullable', 'array', 'max:50'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:50'],
            'tags.*' => ['string', 'max:40'],
        ]);

        return response()->json(['data' => PublicPayload::contact($contacts->update(Contact::query()->findOrFail($id), $data))]);
    }

    /** Record that the contact agreed to (opt-in) or refused (opt-out) messages. Written to the consent ledger. */
    public function optIn(Request $request, string $id, ConsentService $consent): JsonResponse
    {
        return $this->consent($request, $id, true, $consent);
    }

    public function optOut(Request $request, string $id, ConsentService $consent): JsonResponse
    {
        return $this->consent($request, $id, false, $consent);
    }

    private function consent(Request $request, string $id, bool $in, ConsentService $consent): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);
        $contact = Contact::query()->findOrFail($id);
        $in ? $consent->optIn($contact, 'api', $data['note'] ?? 'Recorded through the API') : $consent->optOut($contact, 'api', $data['note'] ?? 'Recorded through the API');

        return response()->json(['data' => PublicPayload::contact($contact->refresh())]);
    }

    /** The number to send from: the one named, or the workspace's only connected number. */
    private function senderNumber(?string $id): PhoneNumber
    {
        if ($id !== null) {
            return PhoneNumber::query()->find($id) ?? throw ValidationException::withMessages(['from' => 'This phone number does not belong to your workspace.']);
        }
        $connected = PhoneNumber::query()->where('status', PhoneNumberStatus::Connected->value)->limit(2)->get();
        if ($connected->count() !== 1) {
            throw ValidationException::withMessages(['from' => $connected->isEmpty()
                ? 'No WhatsApp number is connected to this workspace.'
                : 'This workspace has several numbers: say which one to send from with "from" (GET /phone-numbers lists them).']);
        }

        return $connected->firstOrFail();
    }
}
