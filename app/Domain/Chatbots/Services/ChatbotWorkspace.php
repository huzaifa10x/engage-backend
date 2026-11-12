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
 *   test_endpoints — the only https URL prefixes a Test chat with live integrations may call
 *                (a sandbox API, a test Google Sheet); set by people who manage integrations
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

    public const MAX_TEST_ENDPOINTS = 20;

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

    /** @return list<string> */
    public static function testEndpoints(?Tenant $tenant): array
    {
        return array_values(array_filter((array) (($tenant->settings ?? [])['chatbot']['test_endpoints'] ?? []), 'is_string'));
    }

    /**
     * Each entry is an https URL with a host name (no IP address, no credentials, no query) and
     * at least a "/" path; it is matched as a prefix, so "https://sandbox.example.com/" covers that
     * host and "https://script.google.com/macros/s/ABC/" one sheet only.
     *
     * @param  array<mixed>  $input
     * @return list<string>
     */
    public static function cleanTestEndpoints(array $input): array
    {
        $out = [];
        foreach (array_slice($input, 0, self::MAX_TEST_ENDPOINTS) as $value) {
            $url = trim((string) (is_scalar($value) ? $value : ''));
            $parts = parse_url($url);
            if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['port'])) {
                continue;
            }
            $host = strtolower($parts['host']);
            if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false || preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $host) !== 1 || str_contains($url, '{{')) {
                continue;
            }
            $out[] = 'https://'.$host.(($parts['path'] ?? '') === '' ? '/' : $parts['path']);
        }

        return array_values(array_unique($out));
    }

    /** @param list<string> $endpoints */
    public static function isTestEndpoint(string $url, array $endpoints): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host']) || isset($parts['user']) || isset($parts['port'])) {
            return false;
        }
        $normal = 'https://'.strtolower($parts['host']).($parts['path'] ?? '/');
        foreach ($endpoints as $prefix) {
            // A prefix that ends in "/" matches below it; otherwise the whole path must match or continue with "/".
            if ($normal === $prefix || str_starts_with($normal, str_ends_with($prefix, '/') ? $prefix : $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /** @param array{variables?: array<string, string>, approvals?: bool, test_endpoints?: list<string>} $changes */
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
