<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Sync\UnsubscribeClaimInput;
use App\ApiResource\Input\Sync\UnsubscribeReportInput;
use App\Entity\Unsubscription;
use App\Sync\LeaseGuard;
use App\Sync\Unsubscribe\UnsubscribeReportResult;
use App\Sync\Unsubscribe\UnsubscribeWork;
use App\Unsubscribe\Unsubscriptions;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<UnsubscribeClaimInput|UnsubscribeReportInput, UnsubscribeWork|UnsubscribeReportResult>
 */
final readonly class SyncUnsubscribeProcessor implements ProcessorInterface
{
    public function __construct(
        private LeaseGuard $leaseGuard,
        private Unsubscriptions $unsubscriptions,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UnsubscribeWork|UnsubscribeReportResult
    {
        $result = $this->entityManager->wrapInTransaction(function () use ($data, $uriVariables): UnsubscribeWork|UnsubscribeReportResult {
            $account = $this->leaseGuard->leasedAccount($uriVariables['accountId'] ?? null, $data->workerId);
            $now = new \DateTimeImmutable();

            if ($data instanceof UnsubscribeClaimInput) {
                return new UnsubscribeWork(array_map(
                    static fn (Unsubscription $u): array => ['id' => $u->getId()->toRfc4122(), 'url' => $u->getUri()],
                    $this->unsubscriptions->claim($account, $data->workerId, $now),
                ));
            }

            $id = $uriVariables['requestId'] ?? null;
            $unsubscription = \is_string($id) && Uuid::isValid($id) ? $this->entityManager->find(Unsubscription::class, Uuid::fromString($id), LockMode::PESSIMISTIC_WRITE) : null;
            if (!$unsubscription instanceof Unsubscription || !$unsubscription->getMailAccount()->getId()->equals($account->getId())) {
                // Gone with its account or message in the meantime: nothing left to record.
                return new UnsubscribeReportResult(false);
            }
            if (!$unsubscription->isClaimedBy($data->workerId, $now)) {
                throw new ConflictHttpException('This worker does not hold the claim on the request (any more).');
            }

            $error = trim((string) $data->error);
            match ($data->outcome) {
                UnsubscribeReportInput::DONE => $this->unsubscriptions->reportDone($unsubscription, $now),
                UnsubscribeReportInput::RETRY => $this->unsubscriptions->reportRetry($unsubscription, '' === $error ? 'The sender could not be reached; trying again later.' : $error, $now),
                default => $this->unsubscriptions->reportFailed($unsubscription, '' === $error ? "The sender's server refused the request." : $error, $now),
            };

            return new UnsubscribeReportResult(true);
        });
        $this->entityManager->clear();

        return $result;
    }
}
