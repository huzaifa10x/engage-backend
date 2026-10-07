<?php

declare(strict_types=1);

namespace App\Domain\Developer\Services;

use Illuminate\Validation\ValidationException;

/**
 * Webhook URLs are chosen by customers, and our servers call them. Without a check, a customer
 * could point one at an address inside our own network (the database, the cloud metadata
 * service) and read the answer from the delivery log. Only public HTTPS addresses are allowed,
 * and this is checked both when the URL is saved and again immediately before every delivery
 * (a hostname can be re-pointed later).
 */
class UrlGuard
{
    /** @return list<string> the public IP addresses the host resolves to */
    public function assertPublic(string $url, string $field = 'url'): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages([$field => $message]);
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if ($host === '' || isset($parts['user']) || isset($parts['pass'])) {
            $fail('Enter a full URL such as https://example.com/webhooks/engage.');
        }
        // Plain http is only accepted on a developer's machine.
        if ($scheme !== 'https' && ! ($scheme === 'http' && app()->environment('local', 'testing'))) {
            $fail('The URL must start with https://.');
        }
        if (isset($parts['port']) && ! in_array((int) $parts['port'], [443, 8443], true) && ! app()->environment('local', 'testing')) {
            $fail('Only ports 443 and 8443 are allowed.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolve($host);
        if ($ips === []) {
            $fail('This address could not be found. Check the domain name.');
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                $fail('This address is not reachable from the internet. Use a public URL.');
            }
        }

        return $ips;
    }

    /** @return list<string> */
    protected function resolve(string $host): array
    {
        $ips = [];
        foreach ((array) @dns_get_record($host, DNS_A | DNS_AAAA) as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $ips[] = $ip;
            }
        }

        return $ips !== [] ? $ips : array_values(array_filter((array) @gethostbynamel($host)));
    }
}
