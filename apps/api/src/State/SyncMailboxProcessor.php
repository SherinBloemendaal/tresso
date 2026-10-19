<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Input\Sync\MailboxClaimInput;
use App\ApiResource\Input\Sync\MailboxReportInput;
use App\Entity\MailboxChange;
use App\Enum\MailboxChangeKind;
use App\Mailbox\MailboxChanges;
use App\Sync\LeaseGuard;
use App\Sync\Mailbox\MailboxReportResult;
use App\Sync\Mailbox\MailboxWork;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * @implements ProcessorInterface<MailboxClaimInput|MailboxReportInput, MailboxWork|MailboxReportResult>
 */
final readonly class SyncMailboxProcessor implements ProcessorInterface
{
    public function __construct(
        private LeaseGuard $leaseGuard,
        private MailboxChanges $changes,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MailboxWork|MailboxReportResult
    {
        $result = $this->entityManager->wrapInTransaction(function () use ($data, $uriVariables): MailboxWork|MailboxReportResult {
            $account = $this->leaseGuard->leasedAccount($uriVariables['accountId'] ?? null, $data->workerId);
            $now = new \DateTimeImmutable();

            if ($data instanceof MailboxClaimInput) {
                return new MailboxWork(array_map(fn (MailboxChange $change): array => $this->describe($change), $this->changes->claim($account, $data->workerId, $now)));
            }

            $id = $uriVariables['changeId'] ?? null;
            $change = \is_string($id) && Uuid::isValid($id) ? $this->entityManager->find(MailboxChange::class, Uuid::fromString($id), LockMode::PESSIMISTIC_WRITE) : null;
            if (!$change instanceof MailboxChange || !$change->getMailAccount()->getId()->equals($account->getId())) {
                // Undone in the meantime, e.g. because the message or its folder is gone: nothing left to record.
                return new MailboxReportResult(false);
            }
            if (!$change->isClaimedBy($data->workerId, $now)) {
                throw new ConflictHttpException('This worker does not hold the claim on the change (any more).');
            }

            if (MailboxReportInput::GONE === $data->outcome && MailboxChangeKind::Move !== $change->getKind()) {
                throw new UnprocessableEntityHttpException('Only a move can find its copy gone.');
            }
            if (null !== $data->remoteUid && $account->usesServers() && 1 !== preg_match('/^[0-9]{1,18}$/', $data->remoteUid)) {
                throw new UnprocessableEntityHttpException('An IMAP uid is a number.');
            }
            match ($data->outcome) {
                MailboxReportInput::DONE => $this->changes->done($change, $data->remoteUid),
                MailboxReportInput::GONE => $this->changes->gone($change),
                MailboxReportInput::RETRY => $this->changes->failed($change, $data->workerId, $data->error ?? 'The change could not be made.', false, $now),
                default => $this->changes->failed($change, $data->workerId, $data->error ?? 'The mail server refused the change.', true, $now),
            };

            return new MailboxReportResult(true);
        });
        $this->entityManager->clear();

        return $result;
    }

    /**
     * @return array{id: string, messageId: string, messageIdHeader: ?string, kind: string, seen: ?bool, flagged: ?bool, locations: list<array{folder: string, uidValidity: ?int, remoteUid: string}>, source: ?array{folder: string, uidValidity: ?int, remoteUid: string}, target: ?array{folder: string}}
     */
    private function describe(MailboxChange $change): array
    {
        $isMove = MailboxChangeKind::Move === $change->getKind();
        $source = $change->getSourceFolder();
        $target = $change->getTargetFolder();

        return [
            'id' => $change->getId()->toRfc4122(),
            'messageId' => $change->getMessage()->getId()->toRfc4122(),
            'messageIdHeader' => $change->getMessage()->getMessageIdHeader(),
            'kind' => $change->getKind()->value,
            'seen' => $change->getSeen(),
            'flagged' => $change->getFlagged(),
            'locations' => $isMove ? [] : $this->changes->locations($change),
            'source' => $isMove && null !== $source ? [
                'folder' => $source->getRemoteName(),
                'uidValidity' => $change->getSourceUidValidity(),
                'remoteUid' => (string) $change->getSourceRemoteUid(),
            ] : null,
            'target' => $isMove && null !== $target ? ['folder' => $target->getRemoteName()] : null,
        ];
    }
}
