<?php

declare(strict_types=1);

namespace App\Sync\Change;

use App\Entity\MessageLocation;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Gives every flushed change to a synced entity its owner's next change number, and leaves tombstones for deletes.
 * A message also counts as changed when it gained or lost a folder (a MessageLocation), because clients show folder
 * membership. Changes made in bulk SQL bypass this listener and use ChangeSequencer directly.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final readonly class ChangeTrackingListener
{
    public function __construct(private ChangeSequencer $sequencer)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $entityManager = $args->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();

        /** @var array<int, TracksChanges> $changed */
        $changed = [];
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof TracksChanges) {
                $changed[spl_object_id($entity)] = $entity;
            }
        }
        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if ($entity instanceof TracksChanges && self::touchesClientFields($entity, array_keys($unitOfWork->getEntityChangeSet($entity)))) {
                $changed[spl_object_id($entity)] = $entity;
            }
        }
        foreach ([...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityDeletions()] as $entity) {
            if ($entity instanceof MessageLocation && !$unitOfWork->isScheduledForDelete($entity->getMessage())) {
                $changed[spl_object_id($entity->getMessage())] = $entity->getMessage();
            }
        }

        /** @var list<TracksChanges> $deleted */
        $deleted = array_values(array_filter($unitOfWork->getScheduledEntityDeletions(), static fn (object $e): bool => $e instanceof TracksChanges));

        if ([] === $changed && [] === $deleted) {
            return;
        }

        /** @var array<string, int> $seqByUser */
        $seqByUser = [];
        $seqFor = function (TracksChanges $entity) use (&$seqByUser, $unitOfWork, $entityManager): int {
            $owner = $entity->getChangeOwner();
            $key = $owner->getId()->toRfc4122();
            if (isset($seqByUser[$key])) {
                return $seqByUser[$key];
            }

            if ($unitOfWork->isScheduledForInsert($owner)) {
                $seq = $owner->nextChangeSeqForNewUser();
                $unitOfWork->recomputeSingleEntityChangeSet($entityManager->getClassMetadata($owner::class), $owner);
            } else {
                $seq = $this->sequencer->next($owner->getId());
            }

            return $seqByUser[$key] = $seq;
        };

        foreach ($changed as $entity) {
            $entity->setChangeSeq($seqFor($entity));
            $metadata = $entityManager->getClassMetadata($entity::class);
            if ($unitOfWork->isScheduledForInsert($entity) || $unitOfWork->isScheduledForUpdate($entity)) {
                $unitOfWork->recomputeSingleEntityChangeSet($metadata, $entity);
            } else {
                $unitOfWork->computeChangeSet($metadata, $entity);
            }
        }

        foreach ($deleted as $entity) {
            $this->sequencer->tombstones($entity->getChangeOwner()->getId(), $entity::changeType(), [$entity->getId()->toRfc4122()], $seqFor($entity));
        }
    }

    /**
     * @param list<string> $changedFields
     */
    private static function touchesClientFields(TracksChanges $entity, array $changedFields): bool
    {
        $clientFields = $entity::clientFields();

        return null === $clientFields || [] !== array_intersect($changedFields, $clientFields);
    }
}
