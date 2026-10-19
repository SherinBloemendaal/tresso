<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Entity\MailAccount;
use App\Entity\OutgoingMessage;
use App\Enum\OutgoingStatus;
use App\Realtime\AfterCommitDispatcher;
use App\Realtime\RealtimeNotifier;
use App\Repository\OutgoingMessageRepository;
use App\Storage\ObjectStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * The life of outgoing mail: queued by the user, released at its send time, claimed by the worker that leases the
 * account, then sent, retried later or failed. Every method runs inside the caller's transaction.
 */
final readonly class Outbox
{
    /** How long a worker may take before another worker may claim the message again. */
    public const string CLAIM_TTL = 'PT5M';
    public const int CLAIM_BATCH = 10;
    /** Waits before attempts 2, 3, ...: one minute up to three hours. */
    private const array RETRY_DELAYS = ['PT1M', 'PT5M', 'PT15M', 'PT1H', 'PT3H'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private MimeComposer $composer,
        private ObjectStorage $storage,
        private RealtimeNotifier $notifier,
        private AfterCommitDispatcher $afterCommit,
        private OutgoingMessageRepository $messages,
    ) {
    }

    public function queue(Composition $composition, \DateTimeImmutable $now): OutgoingMessage
    {
        $composed = $this->composer->compose($composition);
        $sha = hash('sha256', $composed->raw);
        $key = 'outgoing/'.substr($sha, 0, 2).'/'.$sha;
        $this->storage->write($key, $composed->raw, 'message/rfc822');

        $message = new OutgoingMessage(
            $composition->account,
            $composed->messageId,
            $composition->subject,
            ['to' => $composition->to, 'cc' => $composition->cc, 'bcc' => $composition->bcc],
            $key,
            \strlen($composed->raw),
            $composition->sendAt,
            $composition->inReplyTo,
            $now,
        );
        $this->entityManager->persist($message);
        $this->entityManager->flush();
        $this->announce($message);

        return $message;
    }

    public function cancel(OutgoingMessage $message): void
    {
        $message->cancel();
        $this->entityManager->flush();
    }

    /** Called at the send time: a scheduled message whose time has come becomes due. */
    public function releaseIfDue(OutgoingMessage $message, \DateTimeImmutable $now): bool
    {
        if (OutgoingStatus::Scheduled !== $message->getStatus() || $message->getSendAt() > $now) {
            return false;
        }
        $message->makeDue();
        $this->entityManager->flush();
        $this->notifier->outboxDue($message->getMailAccount()->getId());

        return true;
    }

    /**
     * The safety net behind the delayed release messages: due scheduled messages of these accounts are released.
     *
     * @param list<string> $accountIds
     */
    public function releaseDue(array $accountIds, \DateTimeImmutable $now): void
    {
        foreach ($this->messages->lockDue($accountIds, $now, 100) as $message) {
            $this->releaseIfDue($message, $now);
        }
    }

    /**
     * @return list<OutgoingMessage>
     */
    public function claim(MailAccount $account, string $workerId, \DateTimeImmutable $now): array
    {
        $claimed = $this->messages->lockClaimable($account, $now, self::CLAIM_BATCH);
        $until = $now->add(new \DateInterval(self::CLAIM_TTL));
        foreach ($claimed as $message) {
            $message->claim($workerId, $until);
        }
        $this->entityManager->flush();

        return $claimed;
    }

    public function reportSent(OutgoingMessage $message, \DateTimeImmutable $now, bool $copiedToSent): void
    {
        $message->markSent($now, $copiedToSent ? null : 'Sent, but its copy could not be saved in the Sent folder.');
        $this->entityManager->flush();
    }

    public function reportFailed(OutgoingMessage $message, string $error): void
    {
        $message->markFailed($error);
        $this->entityManager->flush();
    }

    /** A temporary failure (server busy, connection lost): try again later, or give up after too many attempts. */
    public function reportRetry(OutgoingMessage $message, string $error, \DateTimeImmutable $now): void
    {
        $delay = self::RETRY_DELAYS[min($message->getAttempts(), \count(self::RETRY_DELAYS)) - 1] ?? self::RETRY_DELAYS[0];
        $message->retryAt($now->add(new \DateInterval($delay)), $error);
        $this->entityManager->flush();
        $this->announce($message);
    }

    private function announce(OutgoingMessage $message): void
    {
        match ($message->getStatus()) {
            OutgoingStatus::Queued => $this->notifier->outboxDue($message->getMailAccount()->getId()),
            OutgoingStatus::Scheduled => $this->afterCommit->dispatch(
                new ReleaseOutgoingMessage($message->getId()->toRfc4122()),
                [DelayStamp::delayUntil($message->getSendAt())],
            ),
            default => null,
        };
    }
}
