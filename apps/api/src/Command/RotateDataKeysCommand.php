<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\Crypto\KeyRing;
use App\Security\Crypto\UserKeys;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Re-wraps every user's keys (user keys, index key, and a data key from before user keys) with the current master key
 * (DATA_KEY_CURRENT), and drops virus scan verdicts cached under another master key. Run after adding a new key to
 * DATA_KEYS; once it reports nothing left to re-wrap, the old key can be removed. Content itself is not re-encrypted:
 * it is encrypted with the user keys, which do not change.
 */
#[AsCommand(name: 'app:crypto:rotate-data-keys', description: 'Re-wrap all user keys with the current master key')]
final readonly class RotateDataKeysCommand
{
    private const int BATCH_SIZE = 200;

    public function __construct(
        private Connection $connection,
        private UserKeys $keys,
        private KeyRing $keyRing,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $rewrapped = 0;
        $users = 0;
        foreach (UserSelection::ids($this->connection, [], true, self::BATCH_SIZE, $io) as $id) {
            $rewrapped += $this->keys->rewrap(Uuid::fromString($id));
            ++$users;
        }

        $dropped = $this->connection->executeStatement('DELETE FROM scan_result WHERE key_id IS DISTINCT FROM :current', ['current' => $this->keyRing->currentId()]);

        $io->success(\sprintf('Re-wrapped %d keys of %d users; dropped %d cached scan verdicts of other master keys.', $rewrapped, $users, $dropped));

        return Command::SUCCESS;
    }
}
