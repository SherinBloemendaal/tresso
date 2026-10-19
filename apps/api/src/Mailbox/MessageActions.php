<?php

declare(strict_types=1);

namespace App\Mailbox;

use App\Entity\Folder;
use App\Entity\MailAccount;
use App\Entity\MailboxChange;
use App\Entity\Message;
use App\Entity\MessageLocation;
use App\Enum\FolderRole;
use App\Enum\MessageAction;
use App\Realtime\RealtimeNotifier;
use Doctrine\ORM\EntityManagerInterface;

/**
 * What the user does to messages: read, flag, archive, trash, move. Each takes effect in Tresso at once (clients see
 * it through the delta) and is queued as a {@see MailboxChange} for the worker that leases the account to make on
 * the mail server. Runs inside the caller's transaction.
 */
final readonly class MessageActions
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private RealtimeNotifier $notifier,
    ) {
    }

    /**
     * @param list<Message> $messages the user's own messages
     * @param Folder|null   $target   the folder for {@see MessageAction::Move}, of the messages' account
     * @param Folder|null   $from     for a move: only the copies in this folder move
     *
     * @return int how many messages changed
     *
     * @throws MissingFolder when an account has no Archive or Trash folder to move into; nothing changes then
     */
    public function apply(array $messages, MessageAction $action, ?Folder $target, \DateTimeImmutable $now, ?Folder $from = null): int
    {
        // Every account must have the folder before anything changes, so a mixed selection is all or nothing.
        $targets = [];
        foreach ($messages as $message) {
            $account = $message->getMailAccount();
            $targets[$account->getId()->toRfc4122()] ??= match ($action) {
                // Gmail has no Archive folder: moving out of INBOX into All Mail is how it archives.
                MessageAction::Archive => $this->roleFolder($account, FolderRole::Archive, FolderRole::All),
                MessageAction::Trash => $this->roleFolder($account, FolderRole::Trash),
                MessageAction::Move => $target ?? throw new \LogicException('A move needs a target folder.'),
                default => null,
            };
        }

        $changed = 0;
        $accounts = [];
        foreach ($messages as $message) {
            $account = $message->getMailAccount();
            $folder = $targets[$account->getId()->toRfc4122()];
            $did = match ($action) {
                MessageAction::MarkRead => $this->setFlags($message, true, null, $now),
                MessageAction::MarkUnread => $this->setFlags($message, false, null, $now),
                MessageAction::Flag => $this->setFlags($message, null, true, $now),
                MessageAction::Unflag => $this->setFlags($message, null, false, $now),
                // Archiving takes the message out of the inbox; copies in other folders stay where they are.
                MessageAction::Archive => $this->move($message, $folder, static fn (Folder $copy): bool => FolderRole::Inbox === $copy->getRole(), $now),
                MessageAction::Trash => $this->move($message, $folder, static fn (): bool => true, $now),
                MessageAction::Move => $this->move($message, $folder, static fn (Folder $copy): bool => null === $from || $copy === $from, $now),
            };
            if ($did) {
                ++$changed;
                $accounts[$account->getId()->toRfc4122()] = $account;
            }
        }

        $this->entityManager->flush();
        foreach ($accounts as $account) {
            $this->notifier->outboxDue($account->getId());
        }

        return $changed;
    }

    private function setFlags(Message $message, ?bool $seen, ?bool $flagged, \DateTimeImmutable $now): bool
    {
        $seen = $seen === $message->isSeen() ? null : $seen;
        $flagged = $flagged === $message->isFlagged() ? null : $flagged;
        if (null === $seen && null === $flagged) {
            return false;
        }
        $message->updateFlags($seen ?? $message->isSeen(), $flagged ?? $message->isFlagged(), $message->isAnswered(), $message->isDraft());
        $this->entityManager->persist(MailboxChange::flags($message, $seen, $flagged, $now));

        return true;
    }

    /**
     * @param \Closure(Folder): bool $leaves which of the message's folders it leaves
     */
    private function move(Message $message, ?Folder $target, \Closure $leaves, \DateTimeImmutable $now): bool
    {
        if (null === $target) {
            throw new \LogicException('A move needs a target folder.');
        }
        $moved = false;
        foreach ($this->entityManager->getRepository(MessageLocation::class)->findBy(['message' => $message]) as $location) {
            if ($location->getFolder() === $target || !$leaves($location->getFolder())) {
                continue;
            }
            $change = MailboxChange::move($location, $target, $now);
            $this->entityManager->persist($change);
            $this->entityManager->persist(new MessageLocation($message, $target, $change->placeholderUid()));
            $this->entityManager->remove($location);
            $moved = true;
        }

        return $moved;
    }

    private function roleFolder(MailAccount $account, FolderRole $role, FolderRole ...$fallbacks): Folder
    {
        foreach ([$role, ...$fallbacks] as $candidate) {
            $folder = $this->entityManager->getRepository(Folder::class)->findOneBy(['mailAccount' => $account, 'role' => $candidate], ['remoteName' => 'ASC']);
            if (null !== $folder) {
                return $folder;
            }
        }

        throw new MissingFolder($account, $role);
    }
}
