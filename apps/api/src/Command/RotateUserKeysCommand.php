<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\Crypto\UserKeys;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Gives users a new current key version; new content is encrypted with it at once (sync workers within 5 minutes).
 * What is stored keeps its older version until app:crypto:reencrypt moves it on and deletes the older versions.
 */
#[AsCommand(name: 'app:crypto:rotate-user-keys', description: 'Give users a new current key version')]
final readonly class RotateUserKeysCommand
{
    private const int BATCH_SIZE = 200;

    public function __construct(
        private Connection $connection,
        private UserKeys $keys,
    ) {
    }

    /**
     * @param list<string> $user
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option('Email address of a user to rotate; repeat for several users')]
        array $user = [],
        #[Option('Rotate the keys of every user')]
        bool $all = false,
    ): int {
        if ($all === ([] !== $user)) {
            $io->error('Pass --user=<email> (once or more) or --all.');

            return Command::INVALID;
        }

        $rotated = 0;
        foreach (UserSelection::ids($this->connection, $user, $all, self::BATCH_SIZE, $io) as $id) {
            $version = $this->keys->rotate(Uuid::fromString($id), new \DateTimeImmutable());
            $io->writeln(\sprintf('%s: key version %d is current.', $id, $version), SymfonyStyle::VERBOSITY_VERBOSE);
            ++$rotated;
            $this->keys->reset();
        }

        $io->success(\sprintf('Rotated the keys of %d users. Run app:crypto:reencrypt to move their content to the new keys.', $rotated));

        return Command::SUCCESS;
    }
}
