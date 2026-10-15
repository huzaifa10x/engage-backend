<?php

declare(strict_types=1);

namespace App\Domain\Chatbots\Services;

use App\Domain\Messaging\Services\InboxTools;
use App\Domain\Tenancy\Models\Tenant;

/**
 * Workspace-wide chatbot settings, kept in tenants.settings.chatbot:
 *   variables  — business variables every flow can use as {{business.<key>}} (name, address,
 *                hours, support phone, store link…), filled once and shared by all templates
 *   approvals  — agency mode: a flow can only be published after a reviewer approved that exact draft
 */
final class ChatbotWorkspace
{
    /** Suggested business variables (shown first in the editor; any key may be added). */
    public const SUGGESTED = [
        'name' => 'Business name',
        'phone' => 'Support / WhatsApp number',
        'address' => 'Address',
        'hours' => 'Opening hours (as text)',
        'website' => 'Website',
        'store_url' => 'Online store link',
        'booking_url' => 'Booking link',
        'email' => 'Email',
    ];

    public const MAX_VARIABLES = 60;

    /** @return array<string, string> */
    public static function variables(?Tenant $tenant): array
    {
        $stored = (array) (($tenant->settings ?? [])['chatbot']['variables'] ?? []);
        $out = [];
        foreach ($stored as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $out[$key] = (string) $value;
            }
        }
        if (! isset($out['name']) && $tenant !== null) {
            $out['name'] = (string) $tenant->name;
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $input
     * @return array<string, string>
     */
    public static function cleanVariables(array $input): array
    {
        $out = [];
        foreach (array_slice($input, 0, self::MAX_VARIABLES, true) as $key => $value) {
            $key = strtolower(trim((string) $key));
            if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key) === 1 && is_scalar($value) && trim((string) $value) !== '') {
                $out[$key] = mb_substr(trim((string) $value), 0, 1000);
            }
        }

        return $out;
    }

    public static function approvals(?Tenant $tenant): bool
    {
        return (bool) (($tenant->settings ?? [])['chatbot']['approvals'] ?? false);
    }

    /** @param array{variables?: array<string, string>, approvals?: bool} $changes */
    public static function save(Tenant $tenant, array $changes): void
    {
        $settings = (array) ($tenant->settings ?? []);
        $settings['chatbot'] = array_merge((array) ($settings['chatbot'] ?? []), $changes);
        $tenant->forceFill(['settings' => $settings])->save();
    }

    /** @return list<string> mon … sun */
    public static function openDays(?Tenant $tenant): array
    {
        $hours = InboxTools::settings($tenant)['business_hours'];
        if (! $hours['enabled']) {
            return InboxTools::DAYS;
        }

        return array_keys(array_filter($hours['days'], fn (array $d) => $d['open']));
    }
}
