<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The users a maintenance command works on: those named by email, or with $all everyone, in id order a batch at a
 * time. Unknown addresses are reported and skipped.
 */
final class UserSelection
{
    /**
     * @param list<string> $emails
     *
     * @return \Generator<int, string> user ids
     */
    public static function ids(Connection $connection, array $emails, bool $all, int $batchSize, SymfonyStyle $io): \Generator
    {
        if (!$all) {
            $lowered = array_values(array_unique(array_map(mb_strtolower(...), $emails)));
            /** @var array<string, string> $found email => id */
            $found = $connection->fetchAllKeyValue('SELECT email, id FROM app_user WHERE email IN (:emails) ORDER BY id', ['emails' => $lowered], ['emails' => ArrayParameterType::STRING]);
            foreach (array_diff($lowered, array_keys($found)) as $missing) {
                $io->warning(\sprintf('No user has the email address "%s".', $missing));
            }
            yield from array_values($found);

            return;
        }

        $after = '00000000-0000-0000-0000-000000000000';
        do {
            /** @var list<string> $ids */
            $ids = $connection->fetchFirstColumn('SELECT id FROM app_user WHERE id > CAST(:after AS UUID) ORDER BY id LIMIT '.$batchSize, ['after' => $after]);
            foreach ($ids as $id) {
                yield $id;
                $after = $id;
            }
        } while ($batchSize === \count($ids));
    }
}
