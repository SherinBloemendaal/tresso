<?php

declare(strict_types=1);

namespace App\Command;

use App\Security\Crypto\KeyRing;
use App\Storage\Reencryption;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Moves users' stored data to their current key version: content stored before encryption, and content left on an
 * older version by app:crypto:rotate-user-keys. Older versions are deleted once nothing uses them (and the current
 * one is at least 15 minutes old). Safe to stop and run again at any time; it continues where it stopped.
 *
 * --status only counts what is left. --purge-legacy deletes the plaintext objects from before encryption, once no row
 * anywhere refers to one.
 */
#[AsCommand(name: 'app:crypto:reencrypt', description: 'Encrypt stored data with each user\'s current key')]
final readonly class ReencryptCommand
{
    public function __construct(
        private Connection $connection,
        private Reencryption $reencryption,
        private KeyRing $keyRing,
    ) {
    }

    /**
     * @param list<string> $user
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option('Email address of a user to re-encrypt; repeat for several users')]
        array $user = [],
        #[Option('Re-encrypt the data of every user')]
        bool $all = false,
        #[Option('Rows per batch')]
        int $batch = 200,
        #[Option('Only count what is left, change nothing')]
        bool $status = false,
        #[Option('Delete the plaintext objects stored before encryption; only once nothing refers to them any more')]
        bool $purgeLegacy = false,
    ): int {
        if ($purgeLegacy) {
            return $this->purgeLegacy($io);
        }
        if ($all === ([] !== $user)) {
            $io->error('Pass --user=<email> (once or more) or --all, or --purge-legacy.');

            return Command::INVALID;
        }
        if ($batch < 1) {
            $io->error('--batch must be at least 1.');

            return Command::INVALID;
        }

        return $status ? $this->status($io, $user, $all) : $this->run($io, $user, $all, $batch);
    }

    /**
     * @param list<string> $emails
     */
    private function run(SymfonyStyle $io, array $emails, bool $all, int $batch): int
    {
        $dropped = $this->reencryption->dropUnkeyedScanResults($this->keyRing->currentId());
        if ($dropped > 0) {
            $io->writeln(\sprintf('Dropped %d cached scan verdicts that were not keyed with the current master key.', $dropped));
        }

        $users = $complete = $failed = 0;
        $waiting = [];
        foreach (UserSelection::ids($this->connection, $emails, $all, 200, $io) as $id) {
            ++$users;
            $owner = Uuid::fromString($id);
            $result = $this->reencryption->run($owner, $batch, static function (string $step, int $moved, int $stepFailed) use ($io, $id): void {
                if ($moved > 0 || $stepFailed > 0) {
                    $io->writeln(\sprintf('%s %s: %d re-encrypted, %d not', $id, $step, $moved, $stepFailed));
                }
            });
            $failed += $result->failed;
            if ($result->isComplete()) {
                ++$complete;
                continue;
            }
            if (0 === $result->left) {
                $waiting[] = $id;
                continue;
            }
            $io->writeln(\sprintf('%s: %d rows left on older keys; key versions %s.', $id, $result->left, implode(', ', $result->versions)));
        }

        if ([] !== $waiting) {
            $io->note(\sprintf('%d users have all data on their new key; their older keys go once the new one is 15 minutes old. Run this again then.', \count($waiting)));
        }
        if ($failed > 0) {
            $io->warning(\sprintf('%d rows could not be re-encrypted (see the log); run this again to retry them.', $failed));
        }
        $io->success(\sprintf('%d of %d users are completely on their current key.', $complete, $users));

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @param list<string> $emails
     */
    private function status(SymfonyStyle $io, array $emails, bool $all): int
    {
        $totals = array_fill_keys(Reencryption::STEPS, 0);
        $users = 0;
        foreach (UserSelection::ids($this->connection, $emails, $all, 200, $io) as $id) {
            ++$users;
            foreach ($this->reencryption->remaining(Uuid::fromString($id)) as $step => $count) {
                $totals[$step] += $count;
            }
        }
        $io->table(['What', 'Rows not on the current key'], array_map(static fn (string $step, int $count): array => [$step, $count], array_keys($totals), $totals));
        $io->writeln(\sprintf('%d users looked at. Rows anywhere still on content from before encryption: %d.', $users, $this->reencryption->legacyContentLeft()));

        return Command::SUCCESS;
    }

    private function purgeLegacy(SymfonyStyle $io): int
    {
        $left = $this->reencryption->legacyContentLeft();
        if (0 !== $left) {
            $io->error(\sprintf('%d rows still refer to content from before encryption; run app:crypto:reencrypt --all first.', $left));

            return Command::FAILURE;
        }
        $io->success(\sprintf('Deleted %d plaintext objects from before encryption.', $this->reencryption->purgeLegacyObjects()));

        return Command::SUCCESS;
    }
}
