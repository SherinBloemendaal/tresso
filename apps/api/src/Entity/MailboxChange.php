<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MailboxChangeKind;
use App\Repository\MailboxChangeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A change the user made to a message (read, flagged, moved) that the worker leasing the account still has to make
 * on the mail server. The change shows in Tresso right away; this row is kept until the server confirmed it.
 *
 * A move takes the message's copy out of the source folder at once and puts a placeholder copy in the target folder
 * (remote uid `pending:<change id>`), replaced by the real uid once the server reports it or the folder sync finds
 * the message. The source uid and modseq are kept here, so a move the server refuses can be undone.
 */
#[ORM\Entity(repositoryClass: MailboxChangeRepository::class)]
class MailboxChange
{
    public const string PLACEHOLDER_PREFIX = 'pending:';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MailAccount $mailAccount;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Message $message;

    #[ORM\Column(length: 16, enumType: MailboxChangeKind::class)]
    private MailboxChangeKind $kind;

    /** Flags: the wanted \Seen, or null to leave it. */
    #[ORM\Column(nullable: true)]
    private ?bool $seen = null;

    /** Flags: the wanted \Flagged, or null to leave it. */
    #[ORM\Column(nullable: true)]
    private ?bool $flagged = null;

    /** Move: the folder the copy leaves; null once that folder is gone from the server. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Folder $sourceFolder = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $sourceRemoteUid = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $sourceModSeq = null;

    /** The source folder's UIDVALIDITY when the move was made: a reset folder makes the uid meaningless. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $sourceUidValidity = null;

    /** Move: the folder the copy goes to; null once that folder is gone from the server. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Folder $targetFolder = null;

    #[ORM\Column]
    private int $attempts = 0;

    /** Not offered to a worker before this moment (backoff after a failed attempt). */
    #[ORM\Column]
    private \DateTimeImmutable $notBefore;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $claimedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $claimedUntil = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    private function __construct(Message $message, MailboxChangeKind $kind, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->mailAccount = $message->getMailAccount();
        $this->message = $message;
        $this->kind = $kind;
        $this->notBefore = $now;
        $this->createdAt = $now;
    }

    public static function flags(Message $message, ?bool $seen, ?bool $flagged, \DateTimeImmutable $now): self
    {
        if (null === $seen && null === $flagged) {
            throw new \LogicException('A flag change must change a flag.');
        }
        $change = new self($message, MailboxChangeKind::Flags, $now);
        $change->seen = $seen;
        $change->flagged = $flagged;

        return $change;
    }

    public static function move(MessageLocation $source, Folder $target, \DateTimeImmutable $now): self
    {
        $change = new self($source->getMessage(), MailboxChangeKind::Move, $now);
        $change->sourceFolder = $source->getFolder();
        $change->sourceRemoteUid = $source->getRemoteUid();
        $change->sourceModSeq = $source->getModSeq();
        $change->sourceUidValidity = $source->getFolder()->getUidValidity();
        $change->targetFolder = $target;

        return $change;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMailAccount(): MailAccount
    {
        return $this->mailAccount;
    }

    public function getMessage(): Message
    {
        return $this->message;
    }

    public function getKind(): MailboxChangeKind
    {
        return $this->kind;
    }

    public function getSeen(): ?bool
    {
        return $this->seen;
    }

    public function getFlagged(): ?bool
    {
        return $this->flagged;
    }

    public function getSourceFolder(): ?Folder
    {
        return $this->sourceFolder;
    }

    public function getSourceRemoteUid(): ?string
    {
        return $this->sourceRemoteUid;
    }

    public function getSourceModSeq(): ?int
    {
        return $this->sourceModSeq;
    }

    public function getSourceUidValidity(): ?int
    {
        return $this->sourceUidValidity;
    }

    /** A later move of a copy that was itself still on its way: its source becomes known once that move landed. */
    public function sourceLanded(string $remoteUid, ?int $uidValidity): void
    {
        $this->sourceRemoteUid = $remoteUid;
        $this->sourceUidValidity = $uidValidity;
    }

    public function getTargetFolder(): ?Folder
    {
        return $this->targetFolder;
    }

    /** The remote uid of the placeholder copy a move leaves in the target folder. */
    public function placeholderUid(): string
    {
        return self::PLACEHOLDER_PREFIX.$this->id->toRfc4122();
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function claim(string $workerId, \DateTimeImmutable $until): void
    {
        $this->claimedBy = $workerId;
        $this->claimedUntil = $until;
    }

    /** Due, and not claimed by any worker. */
    public function isAvailable(\DateTimeImmutable $now): bool
    {
        return $this->notBefore <= $now && (null === $this->claimedUntil || $this->claimedUntil < $now);
    }

    public function isClaimedBy(string $workerId, \DateTimeImmutable $now): bool
    {
        return $workerId === $this->claimedBy && null !== $this->claimedUntil && $this->claimedUntil > $now;
    }

    /** Hands the change back unattempted, e.g. because an earlier change of the message has to go first. */
    public function release(): void
    {
        $this->claimedBy = null;
        $this->claimedUntil = null;
    }

    /** The attempt failed for a reason that may pass; offered again after $retryAt. */
    public function retryLater(string $error, \DateTimeImmutable $retryAt): void
    {
        ++$this->attempts;
        $this->lastError = $error;
        $this->notBefore = $retryAt;
        $this->claimedBy = null;
        $this->claimedUntil = null;
    }
}
