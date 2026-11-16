<?php

declare(strict_types=1);

namespace App\Domain\Conversions;

use App\Domain\Tenancy\Models\Tenant;

/**
 * Conversions to Meta stay behind a per-workspace flag until Meta approves Advanced access for
 * whatsapp_business_manage_events: only workspaces we switch on (our own account first) can turn
 * reporting on or report anything. Every other workspace sees and sends nothing.
 * Stored in tenants.settings.meta_conversions.allowed; set by the platform team only.
 */
final class ConversionsAccess
{
    public static function allowed(Tenant $tenant): bool
    {
        return (bool) (((array) ($tenant->settings ?? []))['meta_conversions']['allowed'] ?? false);
    }

    public static function set(Tenant $tenant, bool $allowed): void
    {
        $settings = (array) ($tenant->settings ?? []);
        $settings['meta_conversions'] = ['allowed' => $allowed] + (array) ($settings['meta_conversions'] ?? []);
        $settings['meta_conversions']['allowed'] = $allowed;
        $tenant->forceFill(['settings' => $settings])->save();
    }
}
