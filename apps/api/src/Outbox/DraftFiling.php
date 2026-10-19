<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Entity\Draft;
use App\Entity\DraftCopyRemoval;
use App\Entity\MailAccount;
use App\Entity\OutgoingMessage;
use App\Realtime\RealtimeNotifier;
use App\Repository\DraftCopyRemovalRepository;
use App\Repository\DraftRepository;
use App\Storage\ObjectStorage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Keeps each account's Drafts folder in step with the user's drafts. A draft is filed once it rested a little after
 * the last edit, so typing does not write a new copy every second; the worker appends the new copy and removes the
 * one before it. Copies of drafts that were sent, deleted or moved to another account are removed. A draft being sent
 * stays (out of the clients' sight) until its message went out, so an undo or a failure brings it back whole. Every
 * method runs inside the caller's transaction.
 */
final readonly class DraftFiling
{
    /** How long a draft rests after its last edit before a copy is filed. */
    public const string REST = 'PT20S';
    public const string CLAIM_TTL = 'PT5M';
    public const int CLAIM_BATCH = 20;
    /** How long a draft that cannot be built (a file gone from storage) waits before it is tried again. */
    public const string UNFILEABLE_RETRY = 'P1D';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DraftRepository $drafts,
        private DraftCopyRemovalRepository $removals,
        private MimeComposer $composer,
        private ObjectStorage $storage,
        private RealtimeNotifier $notifier,
        private UploadCollector $uploads,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{files: list<array{draft: Draft, version: int, rawObjectKey: string, messageId: string, previousMessageId: ?string}>, removals: list<DraftCopyRemoval>}
     */
    public function claim(MailAccount $account, string $workerId, \DateTimeImmutable $now): array
    {
        $until = $now->add(new \DateInterval(self::CLAIM_TTL));
        $files = [];
        foreach ($this->drafts->lockFilable($account, $now, $now->sub(new \DateInterval(self::REST)), self::CLAIM_BATCH) as $draft) {
            try {
                $composed = $this->composer->composeDraft($this->composition($draft, $account, $now), $draft->getId(), $draft->getVersion());
            } catch (MissingAttachment $exception) {
                // One draft whose file is gone must not hold up the others. It is tried again a day later, or as soon
                // as the user changes it (a new version files right away after resting).
                $this->logger->warning('A draft could not be filed: {reason}', ['reason' => $exception->getMessage(), 'draft' => $draft->getId()->toRfc4122()]);
                $draft->claimFiling(Draft::UNFILEABLE, $now->add(new \DateInterval(self::UNFILEABLE_RETRY)));
                continue;
            }
            $sha = hash('sha256', $composed->raw);
            $key = 'drafts/'.substr($sha, 0, 2).'/'.$sha;
            $this->storage->write($key, $composed->raw, 'message/rfc822');
            $draft->claimFiling($workerId, $until);
            $files[] = ['draft' => $draft, 'version' => $draft->getVersion(), 'rawObjectKey' => $key, 'messageId' => $composed->messageId, 'previousMessageId' => $draft->getFiledMessageId()];
        }
        $removals = $this->removals->lockClaimable($account, $now, self::CLAIM_BATCH);
        foreach ($removals as $removal) {
            $removal->claim($workerId, $until);
        }
        $this->entityManager->flush();

        return ['files' => $files, 'removals' => $removals];
    }

    /** The worker filed $version; the copy it replaced is gone. */
    public function filed(Draft $draft, int $version, string $messageId): void
    {
        $draft->filed($version, $messageId);
        $this->entityManager->flush();
    }

    public function removed(DraftCopyRemoval $removal): void
    {
        $this->entityManager->remove($removal);
        $this->entityManager->flush();
    }

    /** The draft moves to another account (or none): its copy leaves the old account's Drafts folder. */
    public function accountChanged(Draft $draft, ?MailAccount $previous): void
    {
        if (null !== $previous) {
            $this->removeCopy($draft, $previous);
        }
        $draft->forgetFiledCopy();
    }

    /** The draft was deleted (or its message went out): it goes, with its copy and its attachments. */
    public function discard(Draft $draft): void
    {
        $account = $draft->getMailAccount();
        if (null !== $account) {
            $this->removeCopy($draft, $account);
        }
        $this->uploads->releaseAll($draft->getAttachments());
        foreach ($draft->getAttachments() as $attachment) {
            $draft->detach($attachment);
            $this->entityManager->remove($attachment);
        }
        $this->entityManager->remove($draft);
        $this->entityManager->flush();
    }

    /** The draft goes out as $message: its copy leaves the Drafts folder, and clients no longer see it. */
    public function sending(Draft $draft, OutgoingMessage $message): void
    {
        $account = $draft->getMailAccount();
        if (null !== $account) {
            $this->removeCopy($draft, $account);
        }
        $draft->markSending($message);
        $this->entityManager->flush();
    }

    /** The message $draft was sent as was cancelled or failed: the draft is back as it was, and is filed again. */
    public function restore(Draft $draft): void
    {
        $draft->restoreFromSending();
        $this->entityManager->flush();
    }

    private function removeCopy(Draft $draft, MailAccount $account): void
    {
        $messageId = $draft->getFiledMessageId();
        if (null !== $messageId) {
            $this->entityManager->persist(new DraftCopyRemoval($account, $messageId));
            // A sent or deleted draft should leave the Drafts folder right away, not at the next heartbeat.
            $this->notifier->outboxDue($account->getId());
        }
    }

    private function composition(Draft $draft, MailAccount $account, \DateTimeImmutable $now): Composition
    {
        $recipients = $draft->getRecipients();

        return new Composition(
            $account,
            $recipients['to'],
            $recipients['cc'],
            $recipients['bcc'],
            $draft->getSubject(),
            DraftBody::compose($draft->getHtml(), $draft->getSignature()?->getHtml(), $draft->getQuoteHtml()),
            $draft->getInReplyTo(),
            $now,
            array_map(OutgoingAttachment::fromDraft(...), $draft->getAttachments()),
        );
    }
}
