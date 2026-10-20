<?php

declare(strict_types=1);

namespace App\Batch;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

final readonly class ReleaseBatchHandler
{
    /** Users a sweep releases; the rest wait for the next minute. */
    public const int SWEEP_USERS = 200;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private BatchDelivery $batches,
    ) {
    }

    #[AsMessageHandler]
    public function release(ReleaseBatch $message): void
    {
        if (Uuid::isValid($message->userId)) {
            $this->releaseUser(Uuid::fromString($message->userId));
        }
    }

    #[AsMessageHandler]
    public function sweep(SweepBatches $message): void
    {
        foreach ($this->batches->usersWithDueMail(new \DateTimeImmutable(), self::SWEEP_USERS) as $userId) {
            $this->releaseUser(Uuid::fromString($userId));
        }
    }

    /** Each part in its own transaction, so change numbers commit in order and a large batch holds no lock for long. */
    private function releaseUser(Uuid $userId): void
    {
        do {
            $more = $this->entityManager->wrapInTransaction(fn (): bool => $this->batches->releaseDue($userId, new \DateTimeImmutable()));
            $this->entityManager->clear();
        } while ($more);
    }
}
