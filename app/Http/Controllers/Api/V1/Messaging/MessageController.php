<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Application\Messaging\SendMessage;
use App\Application\WhatsApp\NumberAccess;
use App\Domain\Messaging\Enums\MessageOrigin;
use App\Domain\Messaging\Enums\MessageStatus;
use App\Domain\Messaging\Events\MessageStored;
use App\Domain\Messaging\Jobs\SendWhatsappMessage;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\MessagingLimits;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
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
    public function start(Request $request, SendMessage $send): JsonResponse
    {
        $data = $request->validate([
            'phone_number_id' => ['required', 'uuid'],
            'contact_id' => ['required_without:to', 'nullable', 'uuid'],
            'to' => ['required_without:contact_id', 'nullable', 'string', 'max:32'],
        ]);

        $number = PhoneNumber::query()->findOrFail($data['phone_number_id']);
        $this->access->ensureCanAccess($this->membership(), $number);

        if (! empty($data['contact_id'])) {
            $contact = Contact::query()->findOrFail($data['contact_id']);
        } else {
            $waId = Contact::checkedPhone((string) $data['to'], 'to');
            $contact = Contact::query()->withTrashed()->where('wa_id', $waId)->first() ?? Contact::query()->create(['wa_id' => $waId, 'source' => 'api']);
            if ($contact->trashed()) {
                $contact->restore();
            }
        }

        // Agents only work on their own chats: not on a chat that belongs to a teammate, and a chat
        // they start is theirs (so it stays in their list).
        $me = $this->membership();
        $agent = ! $this->access->seesAllChats($me);
        $existing = Conversation::query()->where('contact_id', $contact->id)->where('phone_number_id', $number->id)->first();
        if ($agent && $existing !== null && $existing->assigned_membership_id !== null && $existing->assigned_membership_id !== $me->id) {
            throw new WhatsappException('This chat is assigned to another teammate.', ErrorCode::NumberAccessDenied, 403);
        }

        $message = $send->toContact($number, $contact, $this->payload($request), MessageOrigin::Agent, $me, $this->idempotencyKey($request));
        if ($agent) {
            $mine = Conversation::query()->whereKey($message->conversation_id)->whereNull('assigned_membership_id')->first();
            $mine?->forceFill(['assigned_membership_id' => $me->id])->save(); // broadcasts the assignment
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
