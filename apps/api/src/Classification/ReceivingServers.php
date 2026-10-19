<?php

declare(strict_types=1);

namespace App\Classification;

use App\Entity\MailAccount;
use App\Enum\MailProvider;

/**
 * Which Authentication-Results header to believe. Anyone can put such a header in a message; only the one the
 * receiving server added counts. Gmail and Microsoft 365 always add theirs at the top, so the topmost header (the one
 * the worker sends) is theirs. Other servers may add none, so there the header counts only when it names a server of
 * the account's own domains (its authserv-id), the way RFC 8601 says receivers should check it.
 */
final class ReceivingServers
{
    /**
     * @return list<string>|null domains the authserv-id must belong to; null when the topmost header is the provider's
     */
    public static function domainsFor(MailAccount $account): ?array
    {
        if (MailProvider::Imap !== $account->getProvider()) {
            return null;
        }

        $domains = [SenderPattern::domainOf($account->getEmailAddress())];
        $host = mb_strtolower($account->getImap()->host ?? '');
        $labels = explode('.', $host);
        // imap.example.com and mail.example.co.uk are run for example.com and example.co.uk.
        $domains[] = \count($labels) >= 3 ? implode('.', \array_slice($labels, 1)) : $host;

        return array_values(array_unique(array_filter($domains, static fn (string $domain): bool => str_contains($domain, '.'))));
    }

    /**
     * Whether the receiving server wrote this Authentication-Results header.
     *
     * @param list<string>|null $domains see {@see domainsFor()}
     */
    public static function wrote(string $header, ?array $domains): bool
    {
        if (null === $domains) {
            return true;
        }
        $authservId = mb_strtolower(trim(explode(';', $header, 2)[0]));
        if ('' === $authservId || str_contains($authservId, '=')) {
            return false;
        }
        $authservId = explode(' ', $authservId)[0];
        foreach ($domains as $domain) {
            if ($authservId === $domain || str_ends_with($authservId, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }
}
