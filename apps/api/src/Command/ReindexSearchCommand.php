<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Search\MessageIndex;
use App\Search\SearchIndexer;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Rebuilds search documents from the database: for the given users, or with --all for everyone, starting from an
 * empty index. Applies the index settings first, so it is also the command to run after they changed. Search keeps
 * working meanwhile, but a user's results are incomplete until their rebuild is done. Regular indexing pauses for a
 * user while their rebuild runs and continues where it ends.
 */
#[AsCommand(name: 'app:search:reindex', description: 'Rebuild the search index for some users or everyone')]
final readonly class ReindexSearchCommand
{
    private const float WAIT_SECONDS = 600.0;

    public function __construct(
        private UserRepository $users,
        private Connection $connection,
        private MessageIndex $index,
        private SearchIndexer $indexer,
    ) {
    }

    /**
     * @param list<string> $user
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option('Email address of a user to reindex; repeat for several users')]
        array $user = [],
        #[Option('Reindex every user, starting from an empty index')]
        bool $all = false,
    ): int {
        if ($all === ([] !== $user)) {
            $io->error('Pass --user=<email> (once or more) or --all.');

            return Command::INVALID;
        }

        $targets = $all ? $this->everyone() : $this->byEmail($user, $io);
        if (null === $targets) {
            return Command::FAILURE;
        }

        $this->index->waitFor($this->index->configure());
        if ($all) {
            $this->index->waitFor($this->index->clear(), self::WAIT_SECONDS);
        }

        $indexed = 0;
        $lastTask = null;
        foreach ($targets as $id => $email) {
            $run = $this->indexer->rebuild(Uuid::fromString($id));
            $indexed += $run->indexed;
            $lastTask = $run->lastTask ?? $lastTask;
            $io->writeln(\sprintf('%s: %d messages', $email, $run->indexed));
        }
        if (null !== $lastTask) {
            $this->index->waitFor($lastTask, self::WAIT_SECONDS);
        }

        $io->success(\sprintf('Indexed %d messages for %d users.', $indexed, \count($targets)));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, string> email per user id
     */
    private function everyone(): array
    {
        /** @var array<string, string> */
        return $this->connection->fetchAllKeyValue('SELECT id, email FROM app_user ORDER BY id');
    }

    /**
     * @param list<string> $emails
     *
     * @return array<string, string>|null email per user id, or null when an address belongs to nobody
     */
    private function byEmail(array $emails, SymfonyStyle $io): ?array
    {
        $targets = [];
        foreach ($emails as $email) {
            $found = $this->users->findOneBy(['email' => mb_strtolower($email)]);
            if (null === $found) {
                $io->error(\sprintf('No user with the email address %s.', $email));

                return null;
            }
            $targets[$found->getId()->toRfc4122()] = $found->getEmail();
        }

        return $targets;
    }
}
