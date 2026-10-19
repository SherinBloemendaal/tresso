<?php

declare(strict_types=1);

namespace App\Sync\Ingest;

use App\ApiResource\Input\Sync\ChangeBatchInput;
use App\Entity\MailAccount;
use App\Entity\MessageLocation;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applies flag changes and removals in one folder. Removing the last location of a message deletes the message.
 */
final readonly class ChangeApplier
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private FolderLookup $folders,
        private MessageJanitor $janitor,
    ) {
    }

    public function apply(MailAccount $account, ChangeBatchInput $batch): ChangeBatchResult
    {
        $result = $this->entityManager->wrapInTransaction(function () use ($account, $batch): array {
            $folder = $this->folders->get($account, $batch->folder);
            $updated = $unknown = 0;

            foreach ($batch->flags as $change) {
                $location = $this->entityManager->getRepository(MessageLocation::class)->findOneBy(['folder' => $folder, 'remoteUid' => $change->remoteUid]);
                if (null === $location) {
                    ++$unknown;
                    continue;
                }
                $location->getMessage()->updateFlags($change->flags->seen, $change->flags->flagged, $change->flags->answered, $change->flags->draft);
                $location->setModSeq($change->modSeq);
                ++$updated;
            }

            $removed = 0;
            if ([] !== $batch->vanished) {
                $removed = (int) $this->entityManager->createQuery('DELETE FROM App\Entity\MessageLocation l WHERE l.folder = :folder AND l.remoteUid IN (:uids)')
                    ->setParameter('folder', $folder->getId(), 'uuid')
                    ->setParameter('uids', array_values(array_unique($batch->vanished)))
                    ->execute();
            }

            foreach ($batch->presence as $range) {
                // Only numeric (IMAP) uids take part; the regex guard keeps the cast safe.
                $removed += (int) $this->connection->executeStatement(
                    "DELETE FROM message_location l
                     WHERE l.folder_id = :folder
                       AND l.remote_uid ~ '^[0-9]{1,18}$'
                       AND CAST(l.remote_uid AS BIGINT) BETWEEN :from AND :to
                       AND NOT (CAST(l.remote_uid AS BIGINT) = ANY(CAST(:uids AS BIGINT[])))",
                    [
                        'folder' => $folder->getId()->toRfc4122(),
                        'from' => $range->fromUid,
                        'to' => $range->toUid,
                        'uids' => '{'.implode(',', $range->uids).'}',
                    ],
                );
            }

            if (null !== $batch->cursor) {
                $folder->updateSyncState($folder->getUidValidity(), $batch->cursor);
            }
            $this->entityManager->flush();

            return [$updated, $removed, $unknown, $this->janitor->removeOrphans($account)];
        });

        return new ChangeBatchResult(...$result);
    }
}
