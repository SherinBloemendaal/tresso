<?php

declare(strict_types=1);

namespace App\Command;

use App\Account\UserDeletion;
use App\Storage\Reencryption;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Deletes a user and everything they own, crypto-shredding their data first ({@see UserDeletion}).
 */
#[AsCommand(name: 'app:users:delete', description: 'Delete a user and crypto-shred their data')]
final readonly class DeleteUserCommand
{
    public function __construct(
        private Connection $connection,
        private UserDeletion $deletion,
        private Reencryption $reencryption,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Email address of the user to delete')]
        string $email,
        #[Option('Delete without asking')]
        bool $force = false,
    ): int {
        $id = $this->connection->fetchOne('SELECT id FROM app_user WHERE email = :email', ['email' => mb_strtolower($email)]);
        if (!\is_string($id)) {
            $io->error(\sprintf('No user has the email address "%s".', $email));

            return Command::FAILURE;
        }
        if (!$force && !$io->confirm(\sprintf('Delete %s and everything they own? Their data cannot be recovered afterwards, not even from backups.', $email), false)) {
            $io->writeln('Nothing deleted.');

            return Command::FAILURE;
        }

        $this->deletion->delete(Uuid::fromString($id));
        if ($this->reencryption->legacyContentLeft() > 0) {
            $io->warning('Content stored before encryption is still in use, so its plaintext objects stay until app:crypto:reencrypt --all and --purge-legacy have run. Some of them may hold this user\'s mail.');
        }
        $io->success(\sprintf('Deleted %s. Their keys are gone; their search documents and stored objects are being purged.', $email));

        return Command::SUCCESS;
    }
}
