<?php

declare(strict_types=1);

namespace App\Domain\Developer\Services;

use Illuminate\Validation\ValidationException;

/**
 * Webhook URLs are chosen by customers, and our servers call them. Without a check, a customer
 * could point one at an address inside our own network (the database, the cloud metadata
 * service) and read the answer from the delivery log. Only public HTTPS addresses are allowed,
 * and this is checked both when the URL is saved and again immediately before every delivery
 * (a hostname can be re-pointed later); callers pin the connection to the checked addresses (pin()).
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
            if (! self::isPublicIp($ip)) {
                $fail('This address is not reachable from the internet. Use a public URL.');
            }
        }

        return $ips;
    }

    /**
     * Ranges PHP's FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE let through but that are not the public
     * internet: carrier-grade NAT (used inside some clouds), IETF protocol assignments, benchmarking,
     * documentation, NAT64 (which can reach IPv4 addresses inside a network) and 6to4 / Teredo.
     */
    private const BLOCKED = ['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '192.0.2.0/24', '198.51.100.0/24', '203.0.113.0/24',
        '64:ff9b::/96', '64:ff9b:1::/48', '2001:db8::/32', '2002::/16', '2001::/32'];

    public static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        $bin = inet_pton($ip);
        foreach (self::BLOCKED as $cidr) {
            [$net, $bits] = explode('/', $cidr);
            $netBin = inet_pton($net);
            if ($bin === false || $netBin === false || strlen($bin) !== strlen($netBin)) {
                continue;
            }
            $bytes = intdiv((int) $bits, 8);
            $rest = (int) $bits % 8;
            if (substr($bin, 0, $bytes) === substr($netBin, 0, $bytes)
                && ($rest === 0 || ((ord($bin[$bytes]) ^ ord($netBin[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0)) {
                return false;
            }
        }

        return true;
    }

    /**
     * curl's CURLOPT_RESOLVE entries that make the request go to the addresses just checked, so a
     * hostname cannot be re-pointed at an internal address between the check and the call (DNS rebinding).
     *
     * @param  list<string>  $ips
     * @return list<string>
     */
    public static function pin(string $url, array $ips): array
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || $ips === []) {
            return [];
        }
        $port = (int) (parse_url($url, PHP_URL_PORT) ?? (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'http' ? 80 : 443));

        return [$host.':'.$port.':'.implode(',', array_map(fn (string $ip) => str_contains($ip, ':') ? "[{$ip}]" : $ip, $ips))];
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
