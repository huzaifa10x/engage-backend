<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Application\Crm\TagCatalog;
use App\Application\Messaging\SendMessage;
use App\Application\WhatsApp\NumberAccess;
use App\Domain\Crm\Services\ContactRecord;
use App\Domain\Messaging\Enums\ConsentState;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Events\MessageStored;
use App\Domain\Messaging\Jobs\SendWhatsappMessage;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationNote;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\InboxTools;
use App\Domain\Messaging\Services\MessagingLimits;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Timeline\Enquiries;
use App\Domain\WhatsApp\Exceptions\WhatsappException;
use App\Domain\WhatsApp\Models\PhoneNumber;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MessageResource;
use App\Support\Api\ErrorCode;
use App\Support\Meta\MetaErrorCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MessageController extends Controller
{
    private const TYPES = ['text', 'image', 'video', 'audio', 'document', 'sticker', 'template', 'reaction'];

    public function __construct(private readonly TenantContext $context, private readonly NumberAccess $access) {}

    /** Newest first; pass ?cursor= from meta.next_cursor to load older messages. */
    public function index(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        $this->access->ensureCanSeeChat($this->membership(), $conversation);
        $perPage = (int) ($request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']])['per_page'] ?? 50);

        return MessageResource::collection(
            Message::query()->with('media')->where('conversation_id', $conversation->id)
                // By when each message happened, so imported history sits where it belongs in time.
                ->orderByDesc('occurred_at')->orderByDesc('id')->cursorPaginate($perPage)
        );
    }

    /** Reply in a thread. Honours the Idempotency-Key header (safe client retries). */
    public function store(Request $request, Conversation $conversation, SendMessage $send): JsonResponse
    {
        $this->access->ensureCanSeeChat($this->membership(), $conversation);

        $message = $send->toConversation($conversation, $this->payload($request), MessageOrigin::Agent, $this->membership(), $this->idempotencyKey($request));

        return MessageResource::make($message->load('media'))->response()->setStatusCode(202);
    }

    /**
     * Sends a failed reply again (3.3, A3). Only a person's message that failed with a temporary error
     * (throttling, a WhatsApp outage) can be retried; anything else needs a different message.
     */
    public function retry(Conversation $conversation, Message $message, MessagingLimits $limits): JsonResponse
    {
        $this->access->ensureCanSeeChat($this->membership(), $conversation);
        abort_unless($message->conversation_id === $conversation->id, 404);
        if ($message->status !== MessageStatus::Failed || ! in_array($message->origin, [MessageOrigin::Agent, MessageOrigin::Api], true)) {
            throw ValidationException::withMessages(['message' => 'Only a failed reply can be sent again.']);
        }
        if (! MetaErrorCatalog::temporary($message->error_code)) {
            throw ValidationException::withMessages(['message' => 'This message failed for a reason that sending again will not fix.']);
        }
        if ($message->type !== 'template' && ! $conversation->isWindowOpen()) {
            throw ValidationException::withMessages(['message' => 'The 24-hour window has closed. Send an approved template instead.']);
        }

        DB::transaction(function () use ($message, $conversation, $limits): void {
            $message->forceFill(['status' => MessageStatus::Queued, 'failed_at' => null, 'error_code' => null, 'error_title' => null, 'error_details' => null])->save();
            $number = PhoneNumber::query()->findOrFail($message->phone_number_id);
            $contact = Contact::query()->withTrashed()->findOrFail($message->contact_id);
            $limits->reserve($message, $conversation, $contact, $number);
        });
        SendWhatsappMessage::dispatch($message->id, $message->phone_number_id)->afterCommit();
        MessageStored::dispatch($message, false);

        return MessageResource::make($message->refresh()->load('media'))->response()->setStatusCode(202);
    }

    /**
     * Start a conversation from a number with a contact (existing or by phone). Outside a
     * customer service window only `template` is accepted.
     */
    public function start(Request $request, SendMessage $send, ContactRecord $record, InboxTools $tools, Enquiries $enquiries): JsonResponse
    {
        $data = $request->validate([
            'phone_number_id' => ['required', 'uuid'],
            'contact_id' => ['required_without:to', 'nullable', 'uuid'],
            'to' => ['required_without:contact_id', 'nullable', 'string', 'max:32'],
            // Start conversation (3.5): a new contact's details, how they agreed to be contacted, the topic
            // and an optional enquiry. Sent by the app; API clients may leave them out.
            'new_contact' => ['sometimes', 'nullable', 'array'],
            'new_contact.name' => ['required_with:new_contact', 'string', 'min:2', 'max:190', 'regex:/^(?=(?:.*\pL){2})[^\d]+$/u'],
            'new_contact.email' => ['nullable', 'email:rfc', 'max:190'],
            'new_contact.company' => ['nullable', 'string', 'max:190'],
            'new_contact.source' => ['nullable', 'string', 'max:60'],
            'new_contact.tags' => ['nullable', 'array', 'max:20'],
            'new_contact.tags.*' => ['string', 'max:40'],
            'permission' => ['sometimes', 'nullable', 'array'],
            'permission.via' => ['required_with:permission', Rule::in(array_keys(ContactRecord::PERMISSION_VIA))],
            'permission.note' => ['required_if:permission.via,other', 'nullable', 'string', 'max:200'],
            'topic' => ['nullable', 'string', 'max:200'],
            'create_enquiry' => ['sometimes', 'boolean'],
        ], [
            'new_contact.name.regex' => 'Use at least 2 letters and no numbers.',
            'new_contact.name.required_with' => 'Add their name.',
            'permission.via.required_with' => 'Say how they agreed to be contacted.',
            'permission.note.required_if' => 'Add a short note on how they agreed.',
        ]);

        $number = PhoneNumber::query()->findOrFail($data['phone_number_id']);
        $this->access->ensureCanAccess($this->membership(), $number);
        $me = $this->membership();

        $waId = null;
        if (! empty($data['contact_id'])) {
            $contact = Contact::query()->findOrFail($data['contact_id']);
        } else {
            $waId = Contact::checkedPhone((string) $data['to'], 'to');
            $contact = Contact::query()->withTrashed()->where('wa_id', $waId)->first();
            // Checked again on save: someone may have added this number since the search.
            if (isset($data['new_contact']) && $contact !== null && ! $contact->trashed()) {
                throw ValidationException::withMessages(['to' => 'Already saved as '.$contact->displayName().'.']);
            }
        }

        // A new contact, or one who never wrote and has no permission on record: the teammate says how
        // they agreed to be contacted. Checked before anything is saved.
        $isNew = $contact === null || $contact->trashed();
        $hasChat = ! $isNew && Conversation::query()->where('contact_id', $contact->id)->exists();
        $onRecord = ! $isNew && ($contact->contact_permission !== null || $contact->last_inbound_at !== null || $contact->consent_state === ConsentState::OptedIn);
        if (! isset($data['permission']) && ($isNew || (! $hasChat && ! $onRecord))) {
            throw ValidationException::withMessages(['permission.via' => 'Say how they agreed to be contacted.']);
        }
        if ($contact === null) {
            $contact = Contact::query()->create(['wa_id' => $waId, 'source' => 'manual', 'owner_membership_id' => $me->id]);
        } elseif ($contact->trashed()) {
            $contact->restore();
        }

        // Agents only work on their own chats: not on a chat that belongs to a teammate, and a chat
        // they start is theirs (so it stays in their list).
        $agent = ! $this->access->seesAllChats($me);
        $existing = Conversation::query()->where('contact_id', $contact->id)->where('phone_number_id', $number->id)->first();
        if ($agent && $existing !== null && $existing->assigned_membership_id !== null && $existing->assigned_membership_id !== $me->id) {
            throw new WhatsappException('This chat is assigned to another teammate.', ErrorCode::NumberAccessDenied, 403);
        }

        if (isset($data['new_contact'])) {
            $new = $data['new_contact'];
            $contact->fill(array_filter(['name' => trim($new['name']), 'email' => $new['email'] ?? null, 'company' => $new['company'] ?? null], fn ($v) => $v !== null && $v !== ''));
            if (! empty($new['source'])) {
                $contact->source_detail = mb_substr($new['source'], 0, 60);
            }
            if (! empty($new['tags'])) {
                $contact->tags = app(TagCatalog::class)->ensure($new['tags']);
            }
            $contact->owner_membership_id ??= $me->id;
            $contact->save();
            $record->note($contact, 'created', 'Contact created', null, 'Started a chat from the inbox');
        }
        if (isset($data['permission']) && (isset($data['new_contact']) || ! $onRecord)) {
            $record->recordPermission($contact, $data['permission']);
        }

        $message = $send->toContact($number, $contact, $this->payload($request), MessageOrigin::Agent, $me, $this->idempotencyKey($request));
        if ($agent) {
            $mine = Conversation::query()->whereKey($message->conversation_id)->whereNull('assigned_membership_id')->first();
            $mine?->forceFill(['assigned_membership_id' => $me->id])->save(); // broadcasts the assignment
        }

        $topic = isset($data['topic']) ? trim($data['topic']) : '';
        if ($request->boolean('create_enquiry')) {
            $enquiry = $enquiries->manual($contact, $topic !== '' ? $topic : null);
            if ($topic !== '') {
                $enquiry->forceFill(['title' => $topic])->save();
            }
        } elseif ($topic !== '') {
            ConversationNote::query()->create(['conversation_id' => $message->conversation_id, 'membership_id' => $me->id, 'body' => 'Topic: '.$topic, 'mentions' => []]);
        }

        // Another teammate's customer keeps its owner, who hears about it.
        $owner = $contact->owner_membership_id;
        if ($owner !== null && $owner !== $me->id && ($member = TenantMembership::query()->active()->find($owner)) !== null) {
            $name = $me->user()->value('name') ?? 'A teammate';
            $tools->notify($member, 'mention', "{$name} started a chat with your customer {$contact->displayName()}", $topic !== '' ? $topic : null, "/inbox?c={$message->conversation_id}");
        }

        return MessageResource::make($message->load('media'))->response()->setStatusCode(202);
    }

    /** @return array{type: string, body?: ?string, media_id?: ?string, template?: ?array<string, mixed>, reply_to?: ?string, content?: ?array<string, mixed>} */
    private function payload(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(self::TYPES)],
            'body' => ['required_if:type,text', 'nullable', 'string', 'max:4096'],
            'media_id' => ['required_if:type,image,video,audio,document,sticker', 'nullable', 'uuid'],
            'reply_to' => ['required_if:type,reaction', 'nullable', 'string', 'max:191'],
            'content' => ['nullable', 'array'],
            'content.emoji' => ['required_if:type,reaction', 'nullable', 'string', 'max:16'],
            'content.preview_url' => ['nullable', 'boolean'],
            'content.filename' => ['nullable', 'string', 'max:240'],
            'content.voice' => ['nullable', 'boolean'],
            'template' => ['required_if:type,template', 'nullable', 'array'],
            'template.name' => ['required_if:type,template', 'nullable', 'string', 'max:512'],
            'template.language' => ['required_if:type,template', 'nullable', 'string', 'max:15'],
            'template.components' => ['nullable', 'array'],
            'template.variables' => ['nullable', 'array'],
            'template.variables.header' => ['nullable', 'array', 'max:1'],
            'template.variables.header.*' => ['nullable', 'string', 'max:60'],
            'template.variables.body' => ['nullable', 'array', 'max:50'],
            'template.variables.body.*' => ['nullable', 'string', 'max:1024'],
            'template.variables.buttons' => ['nullable', 'array', 'max:10'],
            'template.variables.buttons.*' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = $request->header('Idempotency-Key');

        return is_string($key) && $key !== '' ? mb_substr($key, 0, 128) : null;
    }

    private function membership(): TenantMembership
    {
        return $this->context->membership()?->loadMissing('role') ?? abort(403);
    }
}
