<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMembership;
use App\Domain\Tenancy\TenantContext;

/**
 * Who sees contacts' phone numbers (batch 6, item 13). The "see phone numbers" permission decides;
 * the system Agent role has it unless the workspace hides numbers from Agents (Settings › Team).
 * Hidden numbers are masked everywhere they are shown: +971 50 ••• ••67. People who cannot see them
 * still chat with the contact; nothing else changes.
 */
final class PhoneVisibility
{
    public function __construct(private readonly TenantContext $context) {}

    public static function hidesFromAgents(?Tenant $tenant): bool
    {
        return (bool) (($tenant->settings ?? [])['team']['hide_phone_from_agents'] ?? false);
    }

    public function canSee(?TenantMembership $membership = null): bool
    {
        $membership ??= $this->context->membership();
        if ($membership === null) {
            return true; // no signed-in teammate: the API (its own scopes), jobs, exports run by the system
        }
        $role = $membership->loadMissing('role')->role;
        if ($role === null || ! $role->grants(Permission::ContactsViewPhone)) {
            return false;
        }

        return ! ($role->systemRole() === SystemRole::Agent && self::hidesFromAgents($this->context->tenantOrNull()));
    }

    /** The number as this teammate may see it: whole, or masked keeping the country code and the last two digits. */
    public function show(?string $waId): ?string
    {
        if ($waId === null || $waId === '') {
            return null;
        }
        if ($this->canSee()) {
            return '+'.$waId;
        }

        return self::mask($waId);
    }

    public static function mask(string $waId): string
    {
        $digits = preg_replace('/\D+/', '', $waId) ?? '';
        if (strlen($digits) <= 5) {
            return '+'.str_repeat('•', strlen($digits));
        }
        $keep = strlen($digits) > 10 ? 5 : 3; // country code (and area) stay readable

        return '+'.substr($digits, 0, $keep).' '.str_repeat('•', strlen($digits) - $keep - 2).substr($digits, -2);
    }
}
