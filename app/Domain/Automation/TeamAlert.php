<?php

declare(strict_types=1);

namespace App\Domain\Automation;

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\Permission;
use App\Domain\Messaging\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Services\InboxTools;
use App\Domain\Messaging\Services\ReplyState;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Team-side follow-ups (Engagement Plan §IV.1): internal only — they notify or reassign, never
 * message the customer. Each belongs to one wait (the payload's episode); a reply, closing or a new
 * wait makes it obsolete.
 */
abstract class TeamAlert extends ActionHandler
{
    public function __construct(protected readonly InboxTools $tools, protected readonly NumberAccess $access) {}

    /**
     * Still the same wait, still needing a reply?
     *
     * @param  array<string, mixed>  $payload
     */
    protected function stillWaiting(?Conversation $conversation, array $payload): bool
    {
        if ($conversation === null || ReplyState::shown($conversation) !== ReplyState::NEEDS_REPLY) {
            return false;
        }
        $since = $conversation->getAttribute('needs_reply_since');

        return $since !== null && isset($payload['episode']) && Carbon::parse($since)->equalTo(Carbon::parse((string) $payload['episode']));
    }

    /**
     * The assignee, or (unassigned) the Owners and Admins who can use the number.
     *
     * @return Collection<int, TenantMembership>
     */
    protected function owners(Conversation $conversation): Collection
    {
        if ($conversation->assigned_membership_id !== null) {
            $m = TenantMembership::query()->active()->find($conversation->assigned_membership_id);

            return collect($m !== null ? [$m] : []);
        }

        return $this->leads($conversation, []);
    }

    /**
     * Chosen teammates, or all Owners / Admins, who can use the number.
     *
     * @param  list<string>  $ids
     * @return Collection<int, TenantMembership>
     */
    protected function leads(Conversation $conversation, array $ids): Collection
    {
        $number = PhoneNumber::query()->find($conversation->phone_number_id);

        return TenantMembership::query()->active()->with('role')->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))->get()
            ->filter(function (TenantMembership $m) use ($ids, $number) {
                if ($ids === [] && ! ($m->role?->grants(Permission::InboxAssign) ?? false)) {
                    return false;
                }
                try {
                    return $number !== null && $this->access->canAccess($m, $number);
                } catch (Throwable) {
                    return false;
                }
            })->values();
    }

    protected function waited(Conversation $conversation): string
    {
        $since = $conversation->getAttribute('needs_reply_since');

        return $since !== null ? Carbon::parse($since)->diffForHumans(now(), ['syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 2]) : 'a while';
    }

    protected function name(?Contact $contact): string
    {
        return $contact?->displayName() ?? 'A customer';
    }
}
