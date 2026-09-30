<?php

declare(strict_types=1);

use App\Application\WhatsApp\NumberAccess;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WhatsApp\Models\PhoneNumber;
use Illuminate\Support\Facades\Broadcast;

/*
| Private realtime channels (Reverb / Pusher protocol). Authorised at POST /api/broadcasting/auth
| with the SPA session, inside the member's active workspace.
|
|   tenant.{tenantId}.number.{phoneNumberId}   inbox events for one number (message.created / message.updated)
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

    return $ids === null || in_array($number->id, $ids, true);
});
