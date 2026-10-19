<?php

declare(strict_types=1);

namespace App\Calendar;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The addresses that mean "me" for a user in calendar data: their sign-in address and the addresses of all their
 * mail accounts. Used to find their own response among the attendees and to tell whether they organize an event.
 */
final class SelfAddresses implements ResetInterface
{
    /** @var array<string, list<string>> */
    private array $cache = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<string> lower case
     */
    public function of(User $user): array
    {
        $key = $user->getId()->toRfc4122();
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        /** @var list<string> $accounts */
        $accounts = $this->connection->fetchFirstColumn('SELECT email_address FROM mail_account WHERE owner_id = :owner', ['owner' => $key]);

        return $this->cache[$key] = array_values(array_unique(array_map(mb_strtolower(...), [$user->getEmail(), ...$accounts])));
    }

    public function reset(): void
    {
        $this->cache = [];
    }
}
