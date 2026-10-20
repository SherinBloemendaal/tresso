<?php

declare(strict_types=1);

namespace App\HandOver;

use App\Entity\HandOver;
use App\Entity\Message;
use App\Entity\OutgoingMessage;
use App\Entity\Thread;
use App\Enum\FolderRole;
use App\Enum\OutgoingStatus;
use App\Outbox\Composition;
use App\Outbox\Outbox;
use App\Realtime\AfterCommitDispatcher;
use App\Repository\HandOverRepository;
use App\Sync\Ingest\MessageIds;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * The life of a hand-over: recorded after its forward was sent, told about once its due date passes without a reply
 * (a delayed message at the due date, with the worker heartbeat as the safety net, like scheduled mail), nudged, and
 * closed when the delegate replies in the conversation or the user marks it done. Every method runs inside the
 * caller's transaction.
 */
final readonly class HandOvers
{
    /** How many overdue hand-overs one heartbeat marks at most. */
    private const int SWEEP_BATCH = 100;
    /** Mail queued to go out within this counts as sent now: the undo window, not a send-later. */
    private const string FOLLOW_UP_WINDOW = '+1 minute';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private HandOverRepository $handOvers,
        private AfterCommitDispatcher $afterCommit,
        private Outbox $outbox,
    ) {
    }

    public function handOver(Message $message, string $delegateAddress, ?string $delegateName, string $note, \DateTimeImmutable $dueAt, ?OutgoingMessage $forward, \DateTimeImmutable $now): HandOver
    {
        $handOver = new HandOver($message, $delegateAddress, $delegateName, $note, $dueAt, $forward, $now);
        $this->entityManager->persist($handOver);
        $this->entityManager->flush();
        $this->scheduleDue($handOver, $now);

        return $handOver;
    }

    public function reschedule(HandOver $handOver, \DateTimeImmutable $dueAt, \DateTimeImmutable $now): void
    {
        $handOver->reschedule($dueAt, $now);
        $this->entityManager->flush();
        $this->scheduleDue($handOver, $now);
    }

    public function markDone(HandOver $handOver, \DateTimeImmutable $now): void
    {
        $handOver->markDone($now);
        $this->entityManager->flush();
    }

    public function reopen(HandOver $handOver, \DateTimeImmutable $now): void
    {
        $handOver->reopen($now);
        $this->entityManager->flush();
        $this->scheduleDue($handOver, $now);
    }

    public function withdraw(HandOver $handOver): void
    {
        $this->entityManager->remove($handOver);
        $this->entityManager->flush();
    }

    /**
     * Called at the due date: an open hand-over nobody replied to becomes overdue. The user is told through the
     * notification dispatcher once this commits, and by the clients, which see it change.
     */
    public function markOverdueIfDue(HandOver $handOver, \DateTimeImmutable $now): bool
    {
        if (!$handOver->markOverdueIfDue($now)) {
            return false;
        }
        $this->entityManager->flush();
        $this->afterCommit->dispatch(new NotifyOverdueHandOver($handOver->getId()->toRfc4122()));

        return true;
    }

    /**
     * The safety net behind the delayed due messages: overdue hand-overs of these accounts are marked.
     *
     * @param list<string> $accountIds
     */
    public function markDue(array $accountIds, \DateTimeImmutable $now): void
    {
        foreach ($this->handOvers->lockDue($accountIds, $now, self::SWEEP_BATCH) as $handOver) {
            $this->markOverdueIfDue($handOver, $now);
        }
    }

    /**
     * A message was stored: when it is the delegate's answer in a handed-over conversation (see {@see ReplyCheck}),
     * that hand-over is closed.
     *
     * @param list<string>          $references the Message-IDs it refers to (In-Reply-To, References), stored form
     * @param array<string, string> $headers    its signal headers, by lower-cased name
     */
    public function messageArrived(Message $message, array $references, array $headers, bool $junk, ?FolderRole $folderRole, \DateTimeImmutable $now): void
    {
        if (!ReplyCheck::isAnswer($headers, $junk, $folderRole)) {
            return;
        }
        $answered = $this->handOvers->findAnsweredBy($message, $references);
        foreach ($answered as $handOver) {
            $handOver->replied($message, $now);
        }
        if ([] !== $answered) {
            $this->entityManager->flush();
        }
    }

    /**
     * Mail the user sends to the delegate in a handed-over conversation is a follow-up, whether it was the one-click
     * nudge or written in the composer ("Edit first").
     */
    public function messageQueued(OutgoingMessage $outgoing, bool $forward, \DateTimeImmutable $now): void
    {
        // A forward hands something over (perhaps again), it does not follow a hand-over up; mail sent later is not a
        // follow-up yet, and must not stand in the way of a nudge now.
        if ($forward || $outgoing->getSendAt() > $now->modify(self::FOLLOW_UP_WINDOW)) {
            return;
        }
        $followedUp = $this->handOvers->findFollowedUpBy($outgoing);
        foreach ($followedUp as $handOver) {
            $handOver->nudged($outgoing, $now);
        }
        if ([] !== $followedUp) {
            $this->entityManager->flush();
        }
    }

    /** Whether its latest nudge can still be stopped or is on its way: another one now would reach the delegate twice. */
    public static function nudgeUnderWay(HandOver $handOver): bool
    {
        return \in_array($handOver->getLastNudge()?->getStatus(), [OutgoingStatus::Scheduled, OutgoingStatus::Queued, OutgoingStatus::Sending], true);
    }

    /** Two conversations became one: hand-overs follow the one that stays, and reach clients as changed. */
    public function threadsMerged(Thread $gone, Thread $kept): void
    {
        foreach ($this->handOvers->findBy(['thread' => $gone]) as $handOver) {
            $handOver->moveToThread($kept);
        }
    }

    /**
     * Queues a reply to the delegate in the same conversation: to the forward when its copy in Sent is known, else to
     * the message that was handed over. The outbox records it as the latest nudge (see messageQueued).
     */
    public function nudge(HandOver $handOver, string $html, \DateTimeImmutable $sendAt, \DateTimeImmutable $now): OutgoingMessage
    {
        $forward = $handOver->getForward();
        $subject = null === $forward ? $handOver->getSubject() : $forward->getSubject();
        $nudge = $this->outbox->queue(new Composition(
            $handOver->getMailAccount(),
            [['address' => $handOver->getDelegateAddress(), 'name' => $handOver->getDelegateName()]],
            [],
            [],
            self::replySubject($subject),
            $html,
            $this->forwardCopy($handOver) ?? $handOver->getMessage(),
            $sendAt,
        ), $now);
        $this->messageQueued($nudge, false, $now);
        if ($handOver->getLastNudge() !== $nudge) {
            // Neither the message handed over nor the forward's copy is left to reply to: it is the nudge all the same.
            $handOver->nudged($nudge, $now);
            $this->entityManager->flush();
        }

        return $nudge;
    }

    private function forwardCopy(HandOver $handOver): ?Message
    {
        $messageId = MessageIds::normalize($handOver->getForward()?->getMessageIdHeader());
        if (null === $messageId) {
            return null;
        }

        return $this->entityManager->getRepository(Message::class)->findOneBy(['mailAccount' => $handOver->getMailAccount(), 'messageIdHeader' => $messageId]);
    }

    private function scheduleDue(HandOver $handOver, \DateTimeImmutable $now): void
    {
        if (!$handOver->isOpen() || null !== $handOver->getOverdueAt()) {
            return;
        }
        if ($handOver->getDueAt() <= $now) {
            $this->markOverdueIfDue($handOver, $now);

            return;
        }
        $this->afterCommit->dispatch(new HandOverDue($handOver->getId()->toRfc4122()), [DelayStamp::delayUntil($handOver->getDueAt())]);
    }

    private static function replySubject(string $subject): string
    {
        return 1 === preg_match('/^\s*re\s*:/i', $subject) ? $subject : 'Re: '.$subject;
    }
}
