<?php

declare(strict_types=1);

namespace App\Sync\Ingest;

use App\ApiResource\Input\Sync\AddressInput;
use App\ApiResource\Input\Sync\MessageBatchInput;
use App\ApiResource\Input\Sync\MessageInput;
use App\Entity\Attachment;
use App\Entity\Folder;
use App\Entity\MailAccount;
use App\Entity\Message;
use App\Entity\MessageLocation;
use App\Entity\MessageReference;
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
    ) {
    }

    public function ingest(MailAccount $account, MessageBatchInput $batch): MessageBatchResult
    {
        return $this->entityManager->wrapInTransaction(function () use ($account, $batch): MessageBatchResult {
            $folder = $this->folders->get($account, $batch->folder);
            $stored = $linked = $updated = 0;

            foreach ($batch->messages as $input) {
                $location = $this->entityManager->getRepository(MessageLocation::class)->findOneBy(['folder' => $folder, 'remoteUid' => $input->remoteUid]);
                if (null !== $location) {
                    $this->applyFlags($location->getMessage(), $input);
                    $location->setModSeq($input->modSeq);
                    ++$updated;
                    continue;
                }

                $messageId = MessageIds::normalize($input->messageId);
                $existing = $this->findExisting($account, $messageId, $input->rawObjectKey);
                if (null !== $existing) {
                    $this->addLocation($existing, $folder, $input);
                    $this->applyFlags($existing, $input);
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
        $this->applyFlags($message, $input);
        $this->entityManager->persist($message);
        $thread->recordMessage($receivedAt);

        foreach ($references as $reference) {
            $this->entityManager->persist(new MessageReference($message, $reference));
        }

        $hasAttachments = false;
        foreach ($input->attachments as $attachmentInput) {
            $attachment = new Attachment($message, $attachmentInput->filename, $attachmentInput->contentType, $attachmentInput->size, $attachmentInput->sha256);
            if ($attachmentInput->inline && null !== $attachmentInput->contentId) {
                $attachment->markInline($attachmentInput->contentId);
            } else {
                $hasAttachments = true;
            }
            $this->entityManager->persist($attachment);
        }
        $message->setHasAttachments($hasAttachments);

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

    private function applyFlags(Message $message, MessageInput $input): void
    {
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
