<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Entity\OutgoingMessage;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class ReleaseOutgoingMessageHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Outbox $outbox,
    ) {
    }

    public function __invoke(ReleaseOutgoingMessage $message): void
    {
        if (!Uuid::isValid($message->outgoingMessageId)) {
            return;
        }
        $this->entityManager->wrapInTransaction(function () use ($message): void {
            $outgoing = $this->entityManager->find(OutgoingMessage::class, Uuid::fromString($message->outgoingMessageId), LockMode::PESSIMISTIC_WRITE);
            // Cancelled, rescheduled for later (a newer release follows) or already released by the sweep.
            if ($outgoing instanceof OutgoingMessage) {
                $this->outbox->releaseIfDue($outgoing, new \DateTimeImmutable());
            }
        });
    }
}
