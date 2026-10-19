<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use App\Security\Crypto\DataKeyManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-wraps every user's data key with the current master key (DATA_KEY_CURRENT). Run after adding a new key to
 * DATA_KEYS; once it reports nothing left to rotate, the old key can be removed. Credentials themselves are not
 * re-encrypted: they are sealed with the data keys, which do not change.
 */
#[AsCommand(name: 'app:crypto:rotate-data-keys', description: 'Re-wrap all user data keys with the current master key')]
final readonly class RotateDataKeysCommand
{
    private const int BATCH_SIZE = 200;

    public function __construct(
        private UserRepository $users,
        private DataKeyManager $dataKeys,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $rotated = 0;
        $seen = 0;
        $query = $this->users->createQueryBuilder('u')->orderBy('u.id')->getQuery();

        foreach ($query->toIterable() as $user) {
            ++$seen;
            if ($this->dataKeys->rewrap($user)) {
                ++$rotated;
            }
            if (0 === $seen % self::BATCH_SIZE) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }
        $this->entityManager->flush();
        $this->entityManager->clear();

        $io->success(\sprintf('Re-wrapped %d of %d data keys.', $rotated, $seen));

        return Command::SUCCESS;
    }
}
