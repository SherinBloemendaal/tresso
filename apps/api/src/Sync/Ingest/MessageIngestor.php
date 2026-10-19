<?php

declare(strict_types=1);

namespace App\Sync\Ingest;

use App\ApiResource\Input\Sync\AddressInput;
use App\Classification\ClassificationScheduler;
use App\ApiResource\Input\Sync\MessageBatchInput;
use App\ApiResource\Input\Sync\MessageInput;
use App\Calendar\Invitation\InvitationRecorder;
use App\Entity\Attachment;
use App\Entity\Folder;
use App\Entity\MailAccount;
use App\Entity\Message;
use App\Entity\MessageLocation;
use App\Entity\MessageReference;
use App\Entity\MessageSignals;
use App\Entity\MessageText;
use App\Enum\FolderRole;
use App\Mailbox\MailboxChanges;
use App\Repository\MailboxChangeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Stores a batch of messages for one folder. Resending a batch changes nothing: a known (folder, uid) only refreshes
 * flags, and a message already stored for the account (same Message-ID, or same raw content) only gains a location.
 */
final readonly class MessageIngestor
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Threader $threader,
        private FolderLookup $folders,
        private MailboxChangeRepository $mailboxChanges,
        private MailboxChanges $changes,
        private ClassificationScheduler $classification,
        private InvitationRecorder $invitations,
    ) {
    }

    public function ingest(MailAccount $account, MessageBatchInput $batch): MessageBatchResult
    {
        return $this->entityManager->wrapInTransaction(function () use ($account, $batch): MessageBatchResult {
            $folder = $this->folders->get($account, $batch->folder);
            $stored = $linked = $updated = 0;
            $pendingFlags = $this->mailboxChanges->messagesWithPendingFlags($account);

            foreach ($batch->messages as $input) {
                $location = $this->entityManager->getRepository(MessageLocation::class)->findOneBy(['folder' => $folder, 'remoteUid' => $input->remoteUid]);
                if (null !== $location) {
                    $this->applyFlags($location->getMessage(), $input, $pendingFlags);
                    $location->setModSeq($input->modSeq);
                    ++$updated;
                    continue;
                }

                $messageId = MessageIds::normalize($input->messageId);
                $existing = $this->findExisting($account, $messageId, $input->rawObjectKey);
                if (null !== $existing) {
                    // A copy a move from Tresso put here is not new: it lands instead.
                    if (!$this->changes->arrived($existing, $folder, $input->remoteUid, $input->modSeq)) {
                        $this->addLocation($existing, $folder, $input);
                    }
                    $this->applyFlags($existing, $input, $pendingFlags);
                    ++$linked;
                    continue;
                }

                $this->store($account, $folder, $input, $messageId);
                ++$stored;
            }

            if (null !== $batch->cursor) {
                $folder->updateSyncState($folder->getUidValidity(), $batch->cursor);
            }
            $this->entityManager->flush();

            return new MessageBatchResult($stored, $linked, $updated);
        });
    }

    private function store(MailAccount $account, Folder $folder, MessageInput $input, ?string $messageId): void
    {
        $from = $input->from ?? throw new \LogicException('Validation guarantees a sender.');
        $receivedAt = $input->receivedAt ?? throw new \LogicException('Validation guarantees a received date.');
        $references = MessageIds::normalizeList([...$input->references, $input->inReplyTo], $messageId);

        $participants = array_values(array_unique(array_map(
            static fn (AddressInput $address): string => mb_strtolower($address->address),
            [$from, ...$input->to, ...$input->cc],
        )));
        $thread = $this->threader->threadFor($account, $messageId, $references, $input->subject, $participants, $receivedAt);

        $message = new Message($account, $thread, $messageId, $input->subject, $from->address, $from->name, $receivedAt, $input->rawObjectKey);
        $message->setInReplyTo(MessageIds::normalize($input->inReplyTo));
        $message->setSentAt($input->sentAt);
        $message->setSize($input->size);
        $message->setSnippet($input->snippet);
        $message->setRecipients([
            'to' => self::addresses($input->to),
            'cc' => self::addresses($input->cc),
            'bcc' => self::addresses($input->bcc),
            'replyTo' => self::addresses($input->replyTo),
        ]);
        $this->applyFlags($message, $input, []);
        $this->entityManager->persist($message);
        $thread->recordMessage($receivedAt);

        if ([] !== $input->headers || $input->junk) {
            $this->entityManager->persist(new MessageSignals($message, $input->headers, $input->junk));
        }
        $this->classification->mailArrived($account->getOwner()->getId());

        if ('' !== $input->bodyText) {
            $this->entityManager->persist(new MessageText($message, $input->bodyText));
        }

        foreach ($references as $reference) {
            $this->entityManager->persist(new MessageReference($message, $reference));
        }

        $hasAttachments = false;
        foreach ($input->attachments as $attachmentInput) {
            $attachment = new Attachment($message, $attachmentInput->filename, $attachmentInput->contentType, $attachmentInput->size, $attachmentInput->sha256, $attachmentInput->contentId);
            if ($attachmentInput->inline && null !== $attachment->getContentId()) {
                $attachment->markInline();
            } else {
                $hasAttachments = true;
            }
            $this->entityManager->persist($attachment);
        }
        $message->setHasAttachments($hasAttachments);

        // Calendar mail in Spam or Trash is not looked at. It changes the calendar only from the inbox and only when it
        // arrived after the account was connected: the first sync of years of mail must not replay old invitations
        // and cancellations. The rest is kept, so the message still shows what it carries.
        if (!\in_array($folder->getRole(), [FolderRole::Spam, FolderRole::Trash], true)) {
            $apply = FolderRole::Inbox === $folder->getRole() && $receivedAt >= $account->getCreatedAt();
            foreach ($input->calendarParts as $part) {
                $this->invitations->record($message, $part->method, $part->ical, $apply);
            }
        }

        $this->addLocation($message, $folder, $input);

        // Flushed per message so the next message in the batch can thread onto this one.
        $this->entityManager->flush();
    }

    private function findExisting(MailAccount $account, ?string $messageId, string $rawObjectKey): ?Message
    {
        $repository = $this->entityManager->getRepository(Message::class);

        if (null !== $messageId) {
            return $repository->findOneBy(['mailAccount' => $account, 'messageIdHeader' => $messageId]);
        }

        return $repository->findOneBy(['mailAccount' => $account, 'rawObjectKey' => $rawObjectKey]);
    }

    private function addLocation(Message $message, Folder $folder, MessageInput $input): void
    {
        $location = new MessageLocation($message, $folder, $input->remoteUid);
        $location->setModSeq($input->modSeq);
        $this->entityManager->persist($location);
    }

    /**
     * @param array<string, true> $pendingFlags messages with a flag change from Tresso the server has not confirmed:
     *                                          their \Seen and \Flagged stay as the user set them meanwhile
     */
    private function applyFlags(Message $message, MessageInput $input, array $pendingFlags): void
    {
        if (isset($pendingFlags[$message->getId()->toRfc4122()])) {
            $message->updateFlags($message->isSeen(), $message->isFlagged(), $input->flags->answered, $input->flags->draft);

            return;
        }
        $message->updateFlags($input->flags->seen, $input->flags->flagged, $input->flags->answered, $input->flags->draft);
    }

    /**
     * @param list<AddressInput> $addresses
     *
     * @return list<array{address: string, name: ?string}>
     */
    private static function addresses(array $addresses): array
    {
        return array_map(static fn (AddressInput $address): array => ['address' => mb_strtolower($address->address), 'name' => $address->name], $addresses);
    }
}
