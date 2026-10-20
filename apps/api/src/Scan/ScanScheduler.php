<?php

declare(strict_types=1);

namespace App\Scan;

use App\Entity\Attachment;
use App\Entity\DraftAttachment;
use App\Realtime\AfterCommitDispatcher;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Queues a scan for every new attachment, received or attached to a draft, however it was stored, once it is
 * committed. A dispatch lost on the way (the queue down at that moment) is caught by the sweeper.
 */
#[AsEntityListener(event: Events::postPersist, method: 'received', entity: Attachment::class)]
#[AsEntityListener(event: Events::postPersist, method: 'draft', entity: DraftAttachment::class)]
final readonly class ScanScheduler
{
    public function __construct(private AfterCommitDispatcher $afterCommit)
    {
    }

    public function received(Attachment $attachment): void
    {
        $this->afterCommit->dispatch(new ScanAttachment($attachment->getId()->toRfc4122()));
    }

    public function draft(DraftAttachment $attachment): void
    {
        $this->afterCommit->dispatch(new ScanAttachment($attachment->getId()->toRfc4122(), ScanTarget::Draft));
    }
}
