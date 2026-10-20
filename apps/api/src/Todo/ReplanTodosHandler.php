<?php

declare(strict_types=1);

namespace App\Todo;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class ReplanTodosHandler
{
    public function __construct(
        private TodoPlanner $planner,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ReplanTodos $message): void
    {
        // One transaction, so the change numbers of the todos it moves commit in order.
        $this->entityManager->wrapInTransaction(function () use ($message): void {
            $user = Uuid::isValid($message->userId) ? $this->entityManager->find(User::class, Uuid::fromString($message->userId)) : null;
            if (null !== $user) {
                $this->planner->replan($user, $this->clock->now());
            }
        });
        $this->entityManager->clear();
    }
}
