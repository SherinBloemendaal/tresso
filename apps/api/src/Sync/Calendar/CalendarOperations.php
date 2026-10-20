<?php

declare(strict_types=1);

namespace App\Sync\Calendar;

use App\ApiResource\Input\Sync\Calendar\OperationResultInput;
use App\Calendar\CalendarStore;
use App\Entity\CalendarObject;
use App\Entity\CalendarOperation;
use App\Entity\MailAccount;
use App\Enum\CalendarOperationKind;
use App\Repository\CalendarOperationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The worker's side of calendar writes: claim what is due, then report each one. Runs inside the caller's
 * transaction.
 */
final readonly class CalendarOperations
{
    public const string CLAIM_TTL = 'PT5M';
    public const int CLAIM_BATCH = 20;
    public const int MAX_ATTEMPTS = 6;
    private const array RETRY_DELAYS = ['PT1M', 'PT5M', 'PT15M', 'PT1H', 'PT3H'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private CalendarOperationRepository $operations,
        private CalendarSynchronizer $synchronizer,
        private CalendarStore $store,
    ) {
    }

    /**
     * @return list<CalendarOperation>
     */
    public function claim(MailAccount $account, string $workerId, \DateTimeImmutable $now): array
    {
        $claimed = $this->operations->lockClaimable($account, $now, self::CLAIM_BATCH);
        $until = $now->add(new \DateInterval(self::CLAIM_TTL));
        foreach ($claimed as $operation) {
            $operation->claim($workerId, $until);
        }
        $this->entityManager->flush();

        return $claimed;
    }

    public function report(CalendarOperation $operation, OperationResultInput $result, \DateTimeImmutable $now): string
    {
        $object = $operation->getObject();
        $calendar = $operation->getCalendar();
        $error = trim((string) $result->error);

        if (OperationResultInput::RETRY === $result->outcome && null !== $object && null !== $result->remoteId && CalendarOperationKind::Put === $operation->getKind()) {
            // Part of the write reached the provider: Google and Microsoft write a series step by step (the master,
            // then each occurrence), and their worker reports the version the steps so far made. The next attempt is
            // based on it instead of conflicting with it. When no attempt is left, the provider's version that comes
            // with the result replaces Tresso's (below), so the two do not stay apart.
            $object->stored($result->remoteId, $result->etag);
        }

        if (OperationResultInput::RETRY === $result->outcome && $operation->getAttempts() < self::MAX_ATTEMPTS) {
            $delay = self::RETRY_DELAYS[min($operation->getAttempts(), \count(self::RETRY_DELAYS)) - 1] ?? self::RETRY_DELAYS[0];
            $operation->retryAt($now->add(new \DateInterval($delay)), '' === $error ? 'The calendar server could not be reached.' : $error);
            $this->entityManager->flush();

            return 'retry';
        }

        $this->entityManager->remove($operation);
        $this->entityManager->flush();

        if (OperationResultInput::DONE === $result->outcome) {
            if (CalendarOperationKind::Delete === $operation->getKind()) {
                return 'done';
            }
            $remoteId = $result->remoteId ?? $object?->getRemoteId() ?? $operation->getRemoteId();
            if (null === $object) {
                // Removed while its write was under way: the resource now at the provider has to go as well. A
                // delete queued meanwhile carries the version from before this write, so it takes this one instead.
                if (null !== $remoteId) {
                    $queued = $this->operations->findOneBy(['calendar' => $calendar, 'uid' => $operation->getUid(), 'kind' => CalendarOperationKind::Delete]);
                    if (null !== $queued) {
                        $queued->becomeDelete($remoteId, $result->etag);
                    } else {
                        $this->entityManager->persist(CalendarOperation::delete($calendar, $operation->getUid(), $remoteId, $result->etag, $now, $operation->notifies()));
                    }
                    $this->entityManager->flush();
                }

                return 'done';
            }
            if (null !== $remoteId) {
                // Google and Microsoft writes are conditional on an ETag the worker always knows; one it could not read
                // back keeps the last known, so the next write is still conditional (a stale one is a conflict, and the
                // next sync brings the provider's current ETag). CalDAV servers may name none at all.
                $etag = $result->etag ?? ($calendar->getSource()->isProviderApi() ? $object->getEtag() : null);
                $object->stored($remoteId, $etag);
            }
            $this->store->reportSyncError($object, null);
            if (null !== $result->current && null !== $result->current->ical && $result->current->remoteId === $remoteId) {
                // The provider changed the event while storing it (Google and Microsoft add the meeting they made, and
                // their own UID spelling): its version replaces Tresso's, unless a newer change of the user's waits.
                $this->synchronizer->upsert($calendar, $object, $result->current);
            }

            return 'done';
        }

        $message = match ($result->outcome) {
            OperationResultInput::CONFLICT => 'This event was changed elsewhere at the same time; that version was kept.',
            OperationResultInput::RETRY => '' === $error ? 'The calendar server could not be reached; the change was not saved there.' : $error,
            default => '' === $error ? 'The calendar server refused the change.' : $error,
        };

        if (null !== $result->current && null !== $result->current->ical) {
            // The provider's version replaces the local one.
            $this->synchronizer->upsert($calendar, $object, $result->current);
            $object ??= $this->entityManager->getRepository(CalendarObject::class)->findOneBy(['calendar' => $calendar, 'uid' => $operation->getUid()]);
            if (null !== $object) {
                $this->store->reportSyncError($object, $message);
            }

            return 'failed';
        }
        if (OperationResultInput::CONFLICT === $result->outcome && null !== $object) {
            // Gone at the provider.
            $this->store->remove($object, true);

            return 'failed';
        }
        if (null !== $object) {
            $this->store->reportSyncError($object, $message);
        }

        return 'failed';
    }
}
