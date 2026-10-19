<?php

declare(strict_types=1);

namespace App\Sync\Ingest;

use App\ApiResource\Input\Sync\ChangeBatchInput;
use App\Entity\MailAccount;
use App\Entity\MessageLocation;
use App\Sync\Change\ChangeSequencer;
use Doctrine\DBAL\ArrayParameterType;
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
        private ChangeSequencer $sequencer,
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

            /** @var list<string> $affected messages that lost a folder */
            $affected = [];
            if ([] !== $batch->vanished) {
                $affected = $this->connection->fetchFirstColumn(
                    'DELETE FROM message_location WHERE folder_id = :folder AND remote_uid IN (:uids) RETURNING message_id',
                    ['folder' => $folder->getId()->toRfc4122(), 'uids' => array_values(array_unique($batch->vanished))],
                    ['uids' => ArrayParameterType::STRING],
                );
            }

            foreach ($batch->presence as $range) {
                // Only numeric (IMAP) uids take part; the regex guard keeps the cast safe.
                $affected = [...$affected, ...$this->connection->fetchFirstColumn(
                    "DELETE FROM message_location l
                     WHERE l.folder_id = :folder
                       AND l.remote_uid ~ '^[0-9]{1,18}$'
                       AND CAST(l.remote_uid AS BIGINT) BETWEEN :from AND :to
                       AND NOT (CAST(l.remote_uid AS BIGINT) = ANY(CAST(:uids AS BIGINT[])))
                     RETURNING l.message_id",
                    [
                        'folder' => $folder->getId()->toRfc4122(),
                        'from' => $range->fromUid,
                        'to' => $range->toUid,
                        'uids' => '{'.implode(',', $range->uids).'}',
                    ],
                )];
            }
            /** @var list<string> $affected */
            $removed = \count($affected);

            if (null !== $batch->cursor) {
                $folder->updateSyncState($folder->getUidValidity(), $batch->cursor);
            }
            $this->entityManager->flush();

            $deleted = $this->janitor->removeOrphans($account);
            // Messages still in other folders changed their membership.
            $this->sequencer->touchMessages($account->getOwner()->getId(), $affected);

            return [$updated, $removed, $unknown, $deleted];
        });

        return new ChangeBatchResult(...$result);
    }
}
