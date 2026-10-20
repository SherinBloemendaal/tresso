<?php

declare(strict_types=1);

namespace App\HandOver;

use App\Entity\HandOver;
use App\Entity\Message;
use App\Entity\OutgoingMessage;
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
    /** The notification kind, for the user's choice of channels (`channelsByKind`). */
    public const string NOTIFICATION_KIND = 'hand_over';

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

    /** A message was stored: when it is the delegate's reply in a handed-over conversation, that hand-over is closed. */
    public function messageArrived(Message $message, \DateTimeImmutable $now): void
    {
        $answered = $this->handOvers->findAnsweredBy($message);
        foreach ($answered as $handOver) {
            $handOver->replied($message, $now);
        }
        if ([] !== $answered) {
            $this->entityManager->flush();
        }
    }

    /**
     * Queues a reply to the delegate in the same conversation: to the forward when its copy in Sent is known, else to
     * the message that was handed over.
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
        $handOver->nudged($nudge, $now);
        $this->entityManager->flush();

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
