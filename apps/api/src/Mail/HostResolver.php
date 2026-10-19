<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Resolves a host name to its IPv4 and IPv6 addresses. Separate so tests can replace DNS.
 */
class HostResolver
{
    /**
     * @return list<string> addresses; empty when the name does not resolve
     */
    public function resolve(string $host): array
    {
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $records = @dns_get_record($host, \DNS_A | \DNS_AAAA);
        if (false === $records) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (\is_string($address)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }
}
