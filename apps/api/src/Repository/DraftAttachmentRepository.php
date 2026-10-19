<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Draft;
use App\Entity\DraftAttachment;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<DraftAttachment>
 */
class DraftAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DraftAttachment::class);
    }

    public function findById(string $id): ?DraftAttachment
    {
        return Uuid::isValid($id) ? $this->find(Uuid::fromString($id)) : null;
    }

    /** An attachment of one of the owner's drafts that is not being sent. */
    public function findOneOwnedBy(User $owner, Draft $draft, string $id): ?DraftAttachment
    {
        if (!Uuid::isValid($id) || !$draft->getOwner()->getId()->equals($owner->getId())) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($id), 'draft' => $draft]);
    }

    /** Whether any draft still refers to this stored file. */
    public function isReferenced(string $objectKey): bool
    {
        return null !== $this->findOneBy(['objectKey' => $objectKey]);
    }

    /** The bytes of the draft's attachments together. */
    public function totalSize(Draft $draft): int
    {
        $total = $this->createQueryBuilder('a')
            ->select('COALESCE(SUM(a.size), 0)')
            ->where('a.draft = :draft')
            ->setParameter('draft', $draft->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $total;
    }

    /**
     * The attachments of these drafts, by draft id, in their order.
     *
     * @param list<string> $draftIds
     *
     * @return array<string, list<DraftAttachment>>
     */
    public function byDraft(array $draftIds): array
    {
        if ([] === $draftIds) {
            return [];
        }
        /** @var list<DraftAttachment> $attachments */
        $attachments = $this->createQueryBuilder('a')
            ->where('a.draft IN (:drafts)')
            ->setParameter('drafts', array_map(static fn (string $id): string => Uuid::fromString($id)->toRfc4122(), $draftIds))
            ->orderBy('a.position')
            ->getQuery()
            ->getResult();
        $byDraft = [];
        foreach ($attachments as $attachment) {
            $byDraft[$attachment->getDraft()->getId()->toRfc4122()][] = $attachment;
        }

        return $byDraft;
    }
}
