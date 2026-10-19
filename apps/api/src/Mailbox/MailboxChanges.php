<?php

declare(strict_types=1);

namespace App\Mailbox;

use App\Entity\Folder;
use App\Entity\MailAccount;
use App\Entity\MailboxChange;
use App\Entity\Message;
use App\Entity\MessageLocation;
use App\Enum\MailboxChangeKind;
use App\Repository\MailboxChangeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The worker's side of {@see MailboxChange}: it takes the changes it can make now, oldest first, and reports each.
 * A change the server refuses (or that keeps failing) is undone here, so Tresso shows the message as the server has
 * it. Every method runs inside the caller's transaction.
 */
final readonly class MailboxChanges
{
    public const string CLAIM_TTL = 'PT5M';
    public const int CLAIM_BATCH = 100;
    /** Attempts before a change that keeps failing is given up and undone. */
    public const int MAX_ATTEMPTS = 6;
    /** Wait before the next attempt, by attempts made so far. */
    private const array BACKOFF = ['PT30S', 'PT2M', 'PT10M', 'PT30M', 'PT2H'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MailboxChangeRepository $changes,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The changes the worker can make now, in the order the user made them. A message's later changes wait while an
     * earlier one cannot be made yet: it is waiting for a retry, claimed by another worker, a flag change on a copy
     * that is still being moved, or a move of such a copy.
     *
     * @return list<MailboxChange>
     */
    public function claim(MailAccount $account, string $workerId, \DateTimeImmutable $now): array
    {
        $until = $now->add(new \DateInterval(self::CLAIM_TTL));
        $claimed = [];
        /** @var array<string, true> $waiting messages with an earlier change that cannot be made yet */
        $waiting = [];

        foreach ($this->changes->lockPending($account, self::CLAIM_BATCH * 2) as $change) {
            $messageId = $change->getMessage()->getId()->toRfc4122();
            if (isset($waiting[$messageId])) {
                continue;
            }
            if (MailboxChangeKind::Move === $change->getKind() && (null === $change->getSourceFolder() || null === $change->getTargetFolder())) {
                $this->undo($change, 'The folder is gone from the server.');
                continue;
            }
            if (!$this->canMakeNow($change, $now)) {
                $waiting[$messageId] = true;
                continue;
            }
            $change->claim($workerId, $until);
            $claimed[] = $change;
            if (\count($claimed) >= self::CLAIM_BATCH) {
                break;
            }
        }
        $this->entityManager->flush();

        return $claimed;
    }

    /**
     * The copies a flag change applies to, as they are on the server: every copy Tresso knows there, plus the copies
     * later moves will take elsewhere (the flags go on before they move, so they travel along).
     *
     * @return list<array{folder: string, uidValidity: ?int, remoteUid: string}>
     */
    public function locations(MailboxChange $change): array
    {
        $locations = [];
        foreach ($this->entityManager->getRepository(MessageLocation::class)->findBy(['message' => $change->getMessage()]) as $location) {
            if (!$location->isPlaceholder()) {
                $locations[] = ['folder' => $location->getFolder()->getRemoteName(), 'uidValidity' => $location->getFolder()->getUidValidity(), 'remoteUid' => $location->getRemoteUid()];
            }
        }
        foreach ($this->pendingMoves($change) as $move) {
            $source = $move->getSourceFolder();
            if (null !== $source && !str_starts_with((string) $move->getSourceRemoteUid(), MailboxChange::PLACEHOLDER_PREFIX)) {
                $locations[] = ['folder' => $source->getRemoteName(), 'uidValidity' => $move->getSourceUidValidity(), 'remoteUid' => (string) $move->getSourceRemoteUid()];
            }
        }

        return $locations;
    }

    /**
     * The server made the change. For a move, $remoteUid is the copy's uid in the target folder when the server
     * reported it (UIDPLUS); otherwise the folder sync finds the copy later.
     */
    public function done(MailboxChange $change, ?string $remoteUid): void
    {
        if (MailboxChangeKind::Move === $change->getKind() && null !== $remoteUid) {
            $placeholder = $this->placeholder($change);
            if (null !== $placeholder) {
                $this->land($placeholder, $remoteUid, null);
            } else {
                // The user moved the copy on before it landed: that move can go ahead from here.
                $this->sourceLanded($change->placeholderUid(), $remoteUid, $change->getTargetFolder()?->getUidValidity());
            }
        }
        $this->entityManager->remove($change);
        $this->entityManager->flush();
    }

    public function failed(MailboxChange $change, string $error, bool $permanent, \DateTimeImmutable $now): void
    {
        if ($permanent || $change->getAttempts() + 1 >= self::MAX_ATTEMPTS) {
            $this->undo($change, $error);
            $this->entityManager->flush();

            return;
        }
        $wait = self::BACKOFF[min($change->getAttempts(), \count(self::BACKOFF) - 1)];
        $change->retryLater($error, $now->add(new \DateInterval($wait)));
        $this->entityManager->flush();
    }

    /**
     * The folder sync found a copy of $message in $folder that it did not know. Returns whether that copy is one a move
     * from Tresso put there: then it takes the placeholder's place, or, when the user moved it on meanwhile, becomes
     * the source of that next move instead of showing up here again.
     */
    public function arrived(Message $message, Folder $folder, string $remoteUid, ?int $modSeq): bool
    {
        foreach ($this->entityManager->getRepository(MessageLocation::class)->findBy(['message' => $message, 'folder' => $folder]) as $location) {
            if ($location->isPlaceholder()) {
                $this->land($location, $remoteUid, $modSeq);

                return true;
            }
        }
        foreach ($this->changes->findBy(['message' => $message, 'sourceFolder' => $folder]) as $later) {
            $source = (string) $later->getSourceRemoteUid();
            if (!str_starts_with($source, MailboxChange::PLACEHOLDER_PREFIX)) {
                continue;
            }
            $origin = substr($source, \strlen(MailboxChange::PLACEHOLDER_PREFIX));
            if (!Uuid::isValid($origin) || null === $this->entityManager->find(MailboxChange::class, Uuid::fromString($origin))) {
                $later->sourceLanded($remoteUid, $folder->getUidValidity());

                return true;
            }
        }

        return false;
    }

    /**
     * A placeholder copy is now known on the server under $remoteUid: it becomes that copy, unless the folder sync
     * stored the copy already, and later moves of it can go ahead.
     */
    public function land(MessageLocation $placeholder, string $remoteUid, ?int $modSeq): void
    {
        $placeholderUid = $placeholder->getRemoteUid();
        $existing = $this->entityManager->getRepository(MessageLocation::class)->findOneBy(['folder' => $placeholder->getFolder(), 'remoteUid' => $remoteUid]);
        if (null !== $existing) {
            $this->entityManager->remove($placeholder);
        } else {
            $placeholder->landed($remoteUid, $modSeq);
        }
        $this->sourceLanded($placeholderUid, $remoteUid, $placeholder->getFolder()->getUidValidity());
    }

    private function sourceLanded(string $placeholderUid, string $remoteUid, ?int $uidValidity): void
    {
        foreach ($this->changes->findBy(['sourceRemoteUid' => $placeholderUid]) as $later) {
            $later->sourceLanded($remoteUid, $uidValidity);
        }
    }

    private function canMakeNow(MailboxChange $change, \DateTimeImmutable $now): bool
    {
        if (!$change->isAvailable($now)) {
            return false;
        }

        if (MailboxChangeKind::Move === $change->getKind()) {
            return !str_starts_with((string) $change->getSourceRemoteUid(), MailboxChange::PLACEHOLDER_PREFIX);
        }

        // Flags go on copies the server has; while a copy is still on its way, its uid is not known yet.
        foreach ($this->pendingMoves($change) as $move) {
            if (strcmp($move->getId()->toRfc4122(), $change->getId()->toRfc4122()) < 0
                || str_starts_with((string) $move->getSourceRemoteUid(), MailboxChange::PLACEHOLDER_PREFIX)) {
                return false;
            }
        }
        foreach ($this->entityManager->getRepository(MessageLocation::class)->findBy(['message' => $change->getMessage()]) as $location) {
            if ($location->isPlaceholder() && strcmp($location->getRemoteUid(), $change->placeholderUid()) < 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<MailboxChange> the other moves of the change's message that the server has not confirmed yet
     */
    private function pendingMoves(MailboxChange $change): array
    {
        return array_values(array_filter(
            $this->changes->findBy(['message' => $change->getMessage(), 'kind' => MailboxChangeKind::Move], ['id' => 'ASC']),
            static fn (MailboxChange $move): bool => !$move->getId()->equals($change->getId()),
        ));
    }

    /**
     * Gives the change up and puts the message back as the server has it: the flags as they were, or the copy back in
     * the folder it was moved out of. Moves of the copy that waited on this one are given up too.
     */
    private function undo(MailboxChange $change, string $error): void
    {
        $message = $change->getMessage();
        $this->logger->warning('A change to a message could not be made on the mail server and was undone.', [
            'change' => $change->getId()->toRfc4122(),
            'kind' => $change->getKind()->value,
            'account' => $change->getMailAccount()->getId()->toRfc4122(),
            'error' => $error,
        ]);

        if (MailboxChangeKind::Flags === $change->getKind()) {
            // Only what this change set goes back, and only while nothing changed it since.
            $seen = null !== $change->getSeen() && $message->isSeen() === $change->getSeen() ? !$change->getSeen() : $message->isSeen();
            $flagged = null !== $change->getFlagged() && $message->isFlagged() === $change->getFlagged() ? !$change->getFlagged() : $message->isFlagged();
            $message->updateFlags($seen, $flagged, $message->isAnswered(), $message->isDraft());
            $this->entityManager->remove($change);

            return;
        }

        $placeholder = $this->placeholder($change);
        $source = $change->getSourceFolder();
        $sourceUid = (string) $change->getSourceRemoteUid();
        if (null !== $source && !str_starts_with($sourceUid, MailboxChange::PLACEHOLDER_PREFIX)
            && $source->getUidValidity() === $change->getSourceUidValidity()
            && null === $this->entityManager->getRepository(MessageLocation::class)->findOneBy(['folder' => $source, 'remoteUid' => $sourceUid])) {
            $restored = new MessageLocation($message, $source, $sourceUid);
            $restored->setModSeq($change->getSourceModSeq());
            $this->entityManager->persist($restored);
        }
        // Moves of the copy made before it landed go too, whether or not its placeholder is still here.
        foreach ($this->changes->findBy(['sourceRemoteUid' => $change->placeholderUid()]) as $later) {
            $this->undo($later, 'The move it builds on was undone.');
        }
        if (null !== $placeholder) {
            $this->entityManager->remove($placeholder);
        }
        $this->entityManager->remove($change);
    }

    private function placeholder(MailboxChange $change): ?MessageLocation
    {
        $target = $change->getTargetFolder();

        return null === $target ? null : $this->entityManager->getRepository(MessageLocation::class)->findOneBy(['folder' => $target, 'remoteUid' => $change->placeholderUid()]);
    }
}
