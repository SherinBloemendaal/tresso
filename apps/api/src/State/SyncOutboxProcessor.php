<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Sync\OutboxClaimInput;
use App\ApiResource\Input\Sync\OutboxResultInput;
use App\Entity\OutgoingMessage;
use App\Enum\OutgoingStatus;
use App\Outbox\Outbox;
use App\Sync\LeaseGuard;
use App\Sync\Outbox\ClaimedMessage;
use App\Sync\Outbox\ClaimResult;
use App\Sync\Outbox\OutboxResult;
use App\Unsubscribe\Unsubscriptions;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<OutboxClaimInput|OutboxResultInput, ClaimResult|OutboxResult>
 */
final readonly class SyncOutboxProcessor implements ProcessorInterface
{
    public function __construct(
        private LeaseGuard $leaseGuard,
        private Outbox $outbox,
        private EntityManagerInterface $entityManager,
        private Unsubscriptions $unsubscriptions,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ClaimResult|OutboxResult
    {
        $result = $this->entityManager->wrapInTransaction(function () use ($data, $uriVariables): ClaimResult|OutboxResult {
            $account = $this->leaseGuard->leasedAccount($uriVariables['accountId'] ?? null, $data->workerId);
            $now = new \DateTimeImmutable();

            if ($data instanceof OutboxClaimInput) {
                return new ClaimResult(array_map(ClaimedMessage::fromEntity(...), $this->outbox->claim($account, $data->workerId, $now)));
            }

            $id = $uriVariables['messageId'] ?? null;
            $message = \is_string($id) && Uuid::isValid($id)
                ? $this->entityManager->find(OutgoingMessage::class, Uuid::fromString($id), LockMode::PESSIMISTIC_WRITE)
                : null;
            if (!$message instanceof OutgoingMessage || !$message->getMailAccount()->getId()->equals($account->getId())) {
                throw new NotFoundHttpException('No such outgoing message for this account.');
            }
            if (OutgoingStatus::Sending !== $message->getStatus() || $data->workerId !== $message->getClaimedBy()) {
                throw new ConflictHttpException('This worker does not hold the claim on the message (any more).');
            }

            $error = trim((string) $data->error);
            match ($data->outcome) {
                OutboxResultInput::SENT => $this->outbox->reportSent($message, $now, $data->copiedToSent),
                OutboxResultInput::RETRY => $this->outbox->reportRetry($message, '' === $error ? 'Sending failed; trying again later.' : $error, $now),
                default => $this->outbox->reportFailed($message, '' === $error ? 'The mail server refused the message.' : $error),
            };
            // An unsubscribe mail settles its request.
            $this->unsubscriptions->outgoingSettled($message, $now);

            return new OutboxResult($message->getStatus()->value);
        });
        $this->entityManager->clear();

        return $result;
    }
}
