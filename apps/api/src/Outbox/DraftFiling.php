<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Entity\Draft;
use App\Entity\DraftCopyRemoval;
use App\Entity\MailAccount;
use App\Realtime\RealtimeNotifier;
use App\Repository\DraftCopyRemovalRepository;
use App\Repository\DraftRepository;
use App\Storage\ObjectStorage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Keeps each account's Drafts folder in step with the user's drafts. A draft is filed once it rested a little after
 * the last edit, so typing does not write a new copy every second; the worker appends the new copy and removes the
 * one before it. Copies of drafts that were sent, deleted or moved to another account are removed. Every method runs
 * inside the caller's transaction.
 */
final readonly class DraftFiling
{
    /** How long a draft rests after its last edit before a copy is filed. */
    public const string REST = 'PT20S';
    public const string CLAIM_TTL = 'PT5M';
    public const int CLAIM_BATCH = 20;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DraftRepository $drafts,
        private DraftCopyRemovalRepository $removals,
        private MimeComposer $composer,
        private ObjectStorage $storage,
        private RealtimeNotifier $notifier,
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
            $composed = $this->composer->composeDraft($this->composition($draft, $account, $now), $draft->getId(), $draft->getVersion());
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

    /** The draft was sent or deleted: its copy goes too. */
    public function discard(Draft $draft): void
    {
        $account = $draft->getMailAccount();
        if (null !== $account) {
            $this->removeCopy($draft, $account);
        }
        $this->entityManager->remove($draft);
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
        );
    }
}
