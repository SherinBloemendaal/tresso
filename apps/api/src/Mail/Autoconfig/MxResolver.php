<?php

declare(strict_types=1);

namespace App\Mail\Autoconfig;

/**
 * Mail exchangers of a domain, most preferred first.
 */
class MxResolver
{
    /** @return list<string> */
    public function resolve(string $domain): array
    {
        $records = @dns_get_record($domain, \DNS_MX);
        if (!\is_array($records)) {
            return [];
        }
        $exchangers = [];
        foreach ($records as $record) {
            $target = $record['target'] ?? null;
            $priority = $record['pri'] ?? null;
            if (\is_string($target) && '' !== $target && \is_int($priority)) {
                $exchangers[] = ['host' => strtolower(rtrim($target, '.')), 'priority' => $priority];
            }
        }
        usort($exchangers, static fn (array $a, array $b): int => $a['priority'] <=> $b['priority']);

        return array_values(array_unique(array_column($exchangers, 'host')));
    }
}
