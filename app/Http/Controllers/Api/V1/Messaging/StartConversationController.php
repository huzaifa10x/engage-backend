<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messaging;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\PhoneVisibility;
use App\Domain\Crm\Services\ContactRecord;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Start conversation, search first (Inbox & CRM 3.5). Finds the person by name, number in any format,
 * email or company and says what starting a chat with them will do: open the chat they already have,
 * send a template (the 24-hour window is closed), start a new chat, or nothing (no number saved).
 */
final class StartConversationController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly NumberAccess $access) {}

    public function search(Request $request, ContactRecord $record): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'phone_number_id' => ['nullable', 'uuid'],
        ]);
        $term = trim((string) ($data['q'] ?? ''));
        $digits = ltrim((string) preg_replace('/\D+/', '', $term), '0');

        $contacts = Contact::query()
            ->when($term === '', fn ($q) => $q->orderByRaw('greatest(last_inbound_at, updated_at) DESC NULLS LAST'), function ($q) use ($term, $digits) {
                $q->where(fn ($w) => $w
                    ->where('name', 'ilike', "%{$term}%")
                    ->orWhere('profile_name', 'ilike', "%{$term}%")
                    ->orWhere('email', 'ilike', "%{$term}%")
                    ->orWhere('company', 'ilike', "%{$term}%")
                    ->when(strlen($digits) >= 3 && app(PhoneVisibility::class)->canSee(), fn ($w) => $w->orWhere('wa_id', 'like', "%{$digits}%")))
                    ->orderByRaw('last_inbound_at DESC NULLS LAST');
            })
            ->limit($term === '' ? 5 : 8)->get();

        $record->preload($contacts);
        $me = $this->membership();
        $chats = Conversation::query()->whereIn('contact_id', $contacts->pluck('id'))
            ->when($data['phone_number_id'] ?? null, fn ($q, $id) => $q->where('phone_number_id', $id))
            ->orderByDesc('last_message_at')->get()->groupBy('contact_id');
        $names = DB::table('tenant_memberships')->join('users', 'users.id', '=', 'tenant_memberships.user_id')->pluck('users.name', 'tenant_memberships.id')->all();
        $phones = app(PhoneVisibility::class);

        return response()->json(['data' => $contacts->map(function (Contact $c) use ($chats, $record, $me, $names, $phones) {
            $chat = $chats->get($c->id)?->first();
            $action = match (true) {
                $c->wa_id === null => 'no_phone',
                $chat === null => 'start_chat',
                $chat->isWindowOpen() => 'open_chat',
                default => 'send_template',
            };
            $owner = $c->owner_membership_id ?? $chat?->assigned_membership_id;

            return [
                'id' => $c->id,
                'display_name' => $c->displayName(),
                'phone' => $phones->show($c->wa_id),
                'company' => $c->company,
                'lifecycle' => $record->lifecycle($c),
                'opted_out' => $c->consent_state->value === 'opted_out',
                'owner' => $owner === null ? null : ['id' => $owner, 'name' => $names[$owner] ?? 'Former teammate', 'is_me' => $owner === $me->id],
                'conversation' => $chat === null ? null : [
                    'id' => $chat->id, 'status' => $chat->status->value, 'window_expires_at' => $chat->window_expires_at?->toIso8601String(),
                    'window_open' => $chat->isWindowOpen(),
                ],
                'has_permission' => $c->contact_permission !== null || $c->last_inbound_at !== null || $c->consent_state->value === 'opted_in',
                'action' => $action,
                'last_inbound_at' => $c->last_inbound_at?->toIso8601String(),
            ];
        })->values()->all()]);
    }

    private function membership(): TenantMembership
    {
        $m = $this->context->membership();
        abort_unless($m instanceof TenantMembership, 403);

        return $m;
    }
}
