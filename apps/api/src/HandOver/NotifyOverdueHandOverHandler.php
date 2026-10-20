<?php

declare(strict_types=1);

namespace App\HandOver;

use App\Entity\HandOver;
use App\Enum\NotificationKind;
use App\Notification\Channel\PushMessage;
use App\Notification\NotificationChannelChoice;
use App\Notification\NotificationDispatcher;
use App\Repository\NotificationPreferencesRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Tells the user that nobody replied to a hand-over by its due date, through the notification dispatcher: on the phone
 * and the desktop unless they chose other channels for the kind `hand_over`. A tap opens its follow-up in Waiting on.
 * The delivery key holds the due date, so a retried message never notifies twice, and a new due date that passes
 * tells again.
 */
#[AsMessageHandler]
final readonly class NotifyOverdueHandOverHandler
{
    /** Push services drop the notification when they could not deliver it by then. */
    private const string EXPIRES = '+1 day';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private NotificationDispatcher $dispatcher,
        private NotificationPreferencesRepository $preferences,
    ) {
    }

    public function __invoke(NotifyOverdueHandOver $message): void
    {
        if (!Uuid::isValid($message->handOverId)) {
            return;
        }
        $handOver = $this->entityManager->find(HandOver::class, Uuid::fromString($message->handOverId));
        // Withdrawn, answered or done since, or given a new due date: nothing to tell any more.
        if (!$handOver instanceof HandOver || !$handOver->isOpen() || null === $handOver->getOverdueAt()) {
            return;
        }
        $owner = $handOver->getOwner();
        $now = new \DateTimeImmutable();
        $channels = $this->entityManager->wrapInTransaction(fn (): NotificationChannelChoice => $this->preferences->forUser($owner)->channelsFor(NotificationKind::HandOver->value, NotificationKind::HandOver->defaultChannels()));
        $id = $handOver->getId()->toRfc4122();
        $who = $handOver->getDelegateName() ?? $handOver->getDelegateAddress();

        $this->dispatcher->send(
            $owner,
            new PushMessage(
                $id,
                NotificationKind::HandOver->value,
                \sprintf('No reply from %s yet', $who),
                \sprintf('%s is past its due date. Nudge from Waiting on.', '' === $handOver->getSubject() ? '(no subject)' : $handOver->getSubject()),
                '/waiting?nudge='.$id,
                false,
                $now->modify(self::EXPIRES),
                ['handOverId' => $id],
            ),
            \sprintf('handOver:%s:%d', $id, $handOver->getDueAt()->getTimestamp()),
            $channels,
            $now,
        );
    }
}
