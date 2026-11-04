<?php

declare(strict_types=1);

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Access\Permission;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Support\Facades\Broadcast;

/*
| Private realtime channels (Reverb / Pusher protocol). Authorised at POST /api/broadcasting/auth
| with the SPA session, inside the member's active workspace.
|
|   tenant.{tenantId}.number.{phoneNumberId}   inbox events for one number (Owners, Admins: every chat on it)
|   tenant.{tenantId}.member.{membershipId}    inbox events for the chats assigned to that member (agents)
|   tenant.{tenantId}.templates                template list changes (templates.changed)
*/
Broadcast::channel('tenant.{tenantId}.number.{phoneNumberId}', function ($user, string $tenantId, string $phoneNumberId): bool {
    $context = app(TenantContext::class);
    $membership = $context->membership();

    if ($membership === null || $context->id() !== $tenantId) {
        return false;
    }

    $number = PhoneNumber::query()->find($phoneNumberId); // tenant-scoped: another tenant's number is null
    if ($number === null) {
        return false;
    }

    $access = app(NumberAccess::class);
    $ids = $access->grantedIds($membership->loadMissing('role'));

    // Every chat on the number: only for people who see all chats (Owners, Admins). Agents get their
    // own chats on tenant.{tenant}.member.{membership}.
    return ($ids === null || in_array($number->id, $ids, true)) && $access->seesAllChats($membership);
});

Broadcast::channel('tenant.{tenantId}.member.{membershipId}', function ($user, string $tenantId, string $membershipId): bool {
    $context = app(TenantContext::class);
    $membership = $context->membership();

    return $membership !== null && $context->id() === $tenantId && $membership->id === $membershipId && $membership->isActive();
});

Broadcast::channel('tenant.{tenantId}.templates', function ($user, string $tenantId): bool {
    $context = app(TenantContext::class);

    return $context->membership() !== null
        && $context->id() === $tenantId
        && $context->can(Permission::TemplatesView);
});
