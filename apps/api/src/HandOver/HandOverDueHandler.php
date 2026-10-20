<?php

declare(strict_types=1);

namespace App\HandOver;

use App\Entity\HandOver;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class HandOverDueHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private HandOvers $handOvers,
    ) {
    }

    public function __invoke(HandOverDue $message): void
    {
        if (!Uuid::isValid($message->handOverId)) {
            return;
        }
        $this->entityManager->wrapInTransaction(function () use ($message): void {
            $handOver = $this->entityManager->find(HandOver::class, Uuid::fromString($message->handOverId), LockMode::PESSIMISTIC_WRITE);
            // Withdrawn, closed, given a later due date (a newer message follows) or already marked by the sweep.
            if ($handOver instanceof HandOver) {
                $this->handOvers->markOverdueIfDue($handOver, new \DateTimeImmutable());
            }
        });
    }
}
