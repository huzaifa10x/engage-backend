<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\Permission;
use App\Domain\Audit\AuditLogger;
use App\Domain\Messaging\Enums\ConversationStatus;
use App\Domain\Messaging\Jobs\SendReadReceipt;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ConversationResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Team inbox threads. Every query is limited to the numbers this member may use. */
final class ConversationController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly NumberAccess $access) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'phone_number_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::enum(ConversationStatus::class)],
            'assigned' => ['nullable', Rule::in(['me', 'unassigned', 'any'])],
            'unread' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $membership = $this->membership();

        $query = Conversation::query()->with(['contact', 'phoneNumber'])->whereNotNull('last_message_at');
        $this->access->scope($query, $membership, 'phone_number_id');

        $query
            ->when($data['phone_number_id'] ?? null, fn ($q, $id) => $q->where('phone_number_id', $id))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when(($data['assigned'] ?? null) === 'me', fn ($q) => $q->where('assigned_membership_id', $membership->id))
            ->when(($data['assigned'] ?? null) === 'unassigned', fn ($q) => $q->whereNull('assigned_membership_id'))
            ->when($request->boolean('unread'), fn ($q) => $q->where('unread_count', '>', 0))
            ->when($data['q'] ?? null, function ($q, string $term) {
                $digits = preg_replace('/\D+/', '', $term);
                $q->whereHas('contact', fn ($c) => $c->withTrashed()->where(fn ($w) => $w
                    ->where('name', 'ilike', "%{$term}%")
                    ->orWhere('profile_name', 'ilike', "%{$term}%")
                    ->when($digits !== '', fn ($w) => $w->orWhere('wa_id', 'like', "%{$digits}%"))));
            })
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        return ConversationResource::collection($query->cursorPaginate((int) ($data['per_page'] ?? 30)));
    }

    public function show(Conversation $conversation): ConversationResource
    {
        $this->authorizeThread($conversation);

        return ConversationResource::make($conversation->load(['contact', 'phoneNumber']));
    }

    /** Open / close and (re)assign. Assignment needs inbox.assign; anyone with access may close. */
    public function update(Request $request, Conversation $conversation, AuditLogger $audit): ConversationResource
    {
        $this->authorizeThread($conversation);
        $data = $request->validate([
            'status' => ['sometimes', Rule::enum(ConversationStatus::class)],
            'assigned_membership_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        if (array_key_exists('assigned_membership_id', $data)) {
            $this->context->authorize(Permission::InboxAssign);
            if ($data['assigned_membership_id'] !== null) {
                $assignee = TenantMembership::query()->with('role')->findOrFail($data['assigned_membership_id']);
                $this->access->ensureCanAccess($assignee, $conversation->phoneNumber()->firstOrFail());
            }
        }

        $before = ['status' => $conversation->status->value, 'assigned_membership_id' => $conversation->assigned_membership_id];

        if (isset($data['status'])) {
            $conversation->status = ConversationStatus::from($data['status']);
            $conversation->closed_at = $data['status'] === ConversationStatus::Closed->value ? now() : null;
        }
        if (array_key_exists('assigned_membership_id', $data)) {
            $conversation->assigned_membership_id = $data['assigned_membership_id'];
        }
        $conversation->save();

        $audit->record('conversation.updated', $conversation, before: $before, after: $data);

        return ConversationResource::make($conversation->load(['contact', 'phoneNumber']));
    }

    /** Clear unread and send blue ticks for the latest customer message. */
    public function read(Conversation $conversation): ConversationResource
    {
        $this->authorizeThread($conversation);

        if ($conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->save();

            $latest = Message::query()->where('conversation_id', $conversation->id)->where('direction', Message::INBOUND)
                ->whereNotNull('wamid')->where('origin', 'customer')->latest('meta_timestamp')->first();
            if ($latest !== null && $latest->meta_timestamp?->gt(now()->subDays(30))) {
                SendReadReceipt::dispatch($conversation->phone_number_id, (string) $latest->wamid)->afterCommit();
            }
        }

        return ConversationResource::make($conversation->load(['contact', 'phoneNumber']));
    }

    private function membership(): TenantMembership
    {
        return $this->context->membership()?->loadMissing('role') ?? abort(403);
    }

    private function authorizeThread(Conversation $conversation): void
    {
        $this->access->ensureCanAccess($this->membership(), $conversation->phoneNumber()->firstOrFail());
    }
}
