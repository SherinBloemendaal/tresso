<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Sync\Calendar\CalendarListInput;
use App\ApiResource\Input\Sync\Calendar\InventoryInput;
use App\ApiResource\Input\Sync\Calendar\ObjectBatchInput;
use App\ApiResource\Input\Sync\Calendar\OperationResultInput;
use App\ApiResource\Input\Sync\OutboxClaimInput;
use App\Entity\Calendar;
use App\Entity\CalendarOperation;
use App\Entity\MailAccount;
use App\Sync\Calendar\CalendarListResult;
use App\Sync\Calendar\CalendarOperations;
use App\Sync\Calendar\CalendarSynchronizer;
use App\Sync\Calendar\ClaimedOperation;
use App\Sync\Calendar\ClaimResult;
use App\Sync\Calendar\InventoryResult;
use App\Sync\Calendar\ObjectBatchResult;
use App\Sync\Calendar\OperationResult;
use App\Sync\LeaseGuard;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<CalendarListInput|InventoryInput|ObjectBatchInput|OutboxClaimInput|OperationResultInput, CalendarListResult|InventoryResult|ObjectBatchResult|ClaimResult|OperationResult>
 */
final readonly class SyncCalendarProcessor implements ProcessorInterface
{
    public function __construct(
        private LeaseGuard $leaseGuard,
        private CalendarSynchronizer $synchronizer,
        private CalendarOperations $operations,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CalendarListResult|InventoryResult|ObjectBatchResult|ClaimResult|OperationResult
    {
        $result = $this->entityManager->wrapInTransaction(function () use ($data, $uriVariables): CalendarListResult|InventoryResult|ObjectBatchResult|ClaimResult|OperationResult {
            $account = $this->leaseGuard->leasedAccount($uriVariables['accountId'] ?? null, $data->workerId);
            $now = new \DateTimeImmutable();

            return match (true) {
                $data instanceof CalendarListInput => $this->synchronizer->syncCalendars($account, $data),
                $data instanceof InventoryInput => $this->synchronizer->inventory($this->calendar($account, $uriVariables['calendarId'] ?? null), $data),
                $data instanceof ObjectBatchInput => $this->synchronizer->objects($this->calendar($account, $uriVariables['calendarId'] ?? null), $data),
                $data instanceof OutboxClaimInput => new ClaimResult(array_map(ClaimedOperation::fromEntity(...), $this->operations->claim($account, $data->workerId, $now))),
                $data instanceof OperationResultInput => new OperationResult($this->operations->report($this->claimed($account, $uriVariables['operationId'] ?? null, $data->workerId), $data, $now)),
            };
        });
        $this->entityManager->clear();

        return $result;
    }

    private function calendar(MailAccount $account, mixed $id): Calendar
    {
        $calendar = \is_string($id) && Uuid::isValid($id) ? $this->entityManager->find(Calendar::class, Uuid::fromString($id), LockMode::PESSIMISTIC_WRITE) : null;
        if (!$calendar instanceof Calendar || $calendar->getMailAccount()?->getId()->equals($account->getId()) !== true) {
            throw new NotFoundHttpException('No such calendar for this account.');
        }

        return $calendar;
    }

    private function claimed(MailAccount $account, mixed $id, string $workerId): CalendarOperation
    {
        $operation = \is_string($id) && Uuid::isValid($id) ? $this->entityManager->find(CalendarOperation::class, Uuid::fromString($id), LockMode::PESSIMISTIC_WRITE) : null;
        if (!$operation instanceof CalendarOperation || !$operation->getMailAccount()->getId()->equals($account->getId())) {
            throw new NotFoundHttpException('No such calendar operation for this account.');
        }
        if ($workerId !== $operation->getClaimedBy()) {
            throw new ConflictHttpException('This worker does not hold the claim on the operation (any more).');
        }

        return $operation;
    }
}
