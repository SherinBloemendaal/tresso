<?php

declare(strict_types=1);

namespace App\Command;

use App\Classification\MailClassification;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Classifies mail again with the current rules: for the given users, or with --all for everyone. First anything that
 * was never classified, then the conversations of the last --days days (all of them with --days=0). Conversations a
 * user placed themselves stay where they are.
 */
#[AsCommand(name: 'app:classification:rerun', description: 'Classify mail again for some users or everyone')]
final readonly class ReclassifyMailCommand
{
    public function __construct(
        private UserRepository $users,
        private Connection $connection,
        private MailClassification $classification,
    ) {
    }

    /**
     * @param list<string> $user
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option('Email address of a user; repeat for several users')]
        array $user = [],
        #[Option('Every user')]
        bool $all = false,
        #[Option('How many days back to classify again; 0 for all mail')]
        int $days = 30,
    ): int {
        if ($all === ([] !== $user) || $days < 0) {
            $io->error('Pass --user=<email> (once or more) or --all, and --days of 0 or more.');

            return Command::INVALID;
        }

        $targets = $all ? $this->everyone() : $this->byEmail($user, $io);
        if (null === $targets) {
            return Command::FAILURE;
        }

        $since = 0 === $days ? null : new \DateTimeImmutable(\sprintf('-%d days', $days));
        foreach ($targets as $id => $email) {
            $this->classification->classifyNew(Uuid::fromString($id));
            $this->classification->reclassify(Uuid::fromString($id), $since);
            $io->writeln($email);
        }

        $io->success(\sprintf('Classified mail again for %d users.', \count($targets)));

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
