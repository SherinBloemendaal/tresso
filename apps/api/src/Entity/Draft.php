<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DraftKind;
use App\Repository\DraftRepository;
use App\Sync\Change\HidesFromClients;
use App\Sync\Change\TracksChanges;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A message being written, the same on every device. Each save raises the version; a save based on an older version
 * is refused, so two devices never silently overwrite each other. The worker that leases the account files a copy
 * in its Drafts folder (filing state below is internal), so other mail apps see the draft too.
 *
 * Sending keeps the draft, out of sight of the clients, until the message went out: an undo or a failure brings it
 * back as it was, attachments included.
 */
#[ORM\Entity(repositoryClass: DraftRepository::class)]
#[ORM\Index(columns: ['owner_id', 'change_seq'])]
#[ORM\Index(columns: ['mail_account_id', 'filed_version'])]
class Draft implements TracksChanges, HidesFromClients
{
    /** Holds the filing claim of a draft that could not be built, until it changes or a day passed (see DraftFiling). */
    public const string UNFILEABLE = 'unfileable';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $changeSeq = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?MailAccount $mailAccount;

    #[ORM\Column(length: 16, enumType: DraftKind::class)]
    private DraftKind $kind;

    /**
     * @var array{to: list<array{address: string, name: ?string}>, cc: list<array{address: string, name: ?string}>, bcc: list<array{address: string, name: ?string}>}
     */
    #[ORM\Column(type: Types::JSON)]
    private array $recipients = ['to' => [], 'cc' => [], 'bcc' => []];

    #[ORM\Column(length: 998)]
    private string $subject = '';

    /** What the user writes, without signature and quote. */
    #[ORM\Column(type: Types::TEXT)]
    private string $html = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Signature $signature = null;

    /** The original a reply or forward quotes. */
    #[ORM\Column(type: Types::TEXT)]
    private string $quoteHtml = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Message $inReplyTo = null;

    /** 0 until the first write, which every new draft gets right away. */
    #[ORM\Column]
    private int $version = 0;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** The version whose copy is in the Drafts folder; 0 when none is. */
    #[ORM\Column]
    private int $filedVersion = 0;

    /** Message-ID of that copy, without angle brackets. */
    #[ORM\Column(length: 998, nullable: true)]
    private ?string $filedMessageId = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $filingClaimedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $filingClaimedUntil = null;

    /** The message this draft is being sent as; set until it went out (then the draft goes) or came back. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?OutgoingMessage $sendingAs = null;

    /** @var Collection<int, DraftAttachment> */
    #[ORM\OneToMany(targetEntity: DraftAttachment::class, mappedBy: 'draft')]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $attachments;

    public function __construct(Uuid $id, User $owner, ?MailAccount $mailAccount, DraftKind $kind, \DateTimeImmutable $now)
    {
        if (null !== $mailAccount && !$mailAccount->getOwner()->getId()->equals($owner->getId())) {
            throw new \LogicException('A draft can only be written from one of its owner\'s accounts.');
        }
        $this->id = $id;
        $this->owner = $owner;
        $this->mailAccount = $mailAccount;
        $this->kind = $kind;
        $this->updatedAt = $now;
        $this->attachments = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getMailAccount(): ?MailAccount
    {
        return $this->mailAccount;
    }

    public function getKind(): DraftKind
    {
        return $this->kind;
    }

    /**
     * @return array{to: list<array{address: string, name: ?string}>, cc: list<array{address: string, name: ?string}>, bcc: list<array{address: string, name: ?string}>}
     */
    public function getRecipients(): array
    {
        return $this->recipients;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getHtml(): string
    {
        return $this->html;
    }

    public function getSignature(): ?Signature
    {
        return $this->signature;
    }

    public function getQuoteHtml(): string
    {
        return $this->quoteHtml;
    }

    public function getInReplyTo(): ?Message
    {
        return $this->inReplyTo;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getFiledVersion(): int
    {
        return $this->filedVersion;
    }

    public function getFiledMessageId(): ?string
    {
        return $this->filedMessageId;
    }

    /**
     * @param array{to: list<array{address: string, name: ?string}>, cc: list<array{address: string, name: ?string}>, bcc: list<array{address: string, name: ?string}>} $recipients
     */
    public function write(
        ?MailAccount $mailAccount,
        array $recipients,
        string $subject,
        string $html,
        ?Signature $signature,
        string $quoteHtml,
        ?Message $inReplyTo,
        \DateTimeImmutable $now,
    ): void {
        foreach ([$mailAccount?->getOwner(), $signature?->getOwner(), $inReplyTo?->getMailAccount()->getOwner()] as $owner) {
            if (null !== $owner && !$owner->getId()->equals($this->owner->getId())) {
                throw new \LogicException('A draft can only refer to its owner\'s accounts, signatures and messages.');
            }
        }
        $this->mailAccount = $mailAccount;
        $this->recipients = $recipients;
        $this->subject = $subject;
        $this->html = $html;
        $this->signature = $signature;
        $this->quoteHtml = $quoteHtml;
        $this->inReplyTo = $inReplyTo;
        ++$this->version;
        $this->updatedAt = $now;
        $this->releaseUnfileable();
    }

    /** Its signature was deleted. A change clients see, but not a new version of what the user wrote. */
    public function dropSignature(): void
    {
        $this->signature = null;
    }

    /** Its account was removed, and with it the Drafts folder copy. The text stays; sending needs another account. */
    public function detachFromAccount(): void
    {
        $this->mailAccount = null;
        $this->forgetFiledCopy();
    }

    /** @return list<DraftAttachment> */
    public function getAttachments(): array
    {
        return array_values($this->attachments->toArray());
    }

    /** Called by DraftAttachment's creator, so the draft's own list stays current in this unit of work. */
    public function attach(DraftAttachment $attachment): void
    {
        if ($attachment->getDraft() !== $this) {
            throw new \LogicException('An attachment belongs to the draft it was made for.');
        }
        $this->attachments->add($attachment);
    }

    public function detach(DraftAttachment $attachment): void
    {
        $this->attachments->removeElement($attachment);
    }

    /** The position after the last attachment. */
    public function nextAttachmentPosition(): int
    {
        $last = 0;
        foreach ($this->attachments as $attachment) {
            $last = max($last, $attachment->getPosition());
        }

        return $last + 1;
    }

    public function getSendingAs(): ?OutgoingMessage
    {
        return $this->sendingAs;
    }

    public function isSending(): bool
    {
        return null !== $this->sendingAs;
    }

    /** The draft goes out as this message: clients no longer see it, and its copy leaves the Drafts folder. */
    public function markSending(OutgoingMessage $message): void
    {
        if (null !== $this->sendingAs) {
            throw new \LogicException('This draft is being sent already.');
        }
        $this->sendingAs = $message;
        $this->forgetFiledCopy();
    }

    /** The message was cancelled or failed: the draft is back as it was, and is filed again. */
    public function restoreFromSending(): void
    {
        $this->sendingAs = null;
        $this->forgetFiledCopy();
    }

    /**
     * Its files changed (one added or removed): that is a new version, so the copy in the Drafts folder is filed
     * again and other devices take the change.
     */
    public function filesChanged(\DateTimeImmutable $now): void
    {
        ++$this->version;
        $this->updatedAt = $now;
        $this->releaseUnfileable();
    }

    /** A change may have put right what kept the draft from being filed: it is tried again. */
    private function releaseUnfileable(): void
    {
        if (self::UNFILEABLE === $this->filingClaimedBy) {
            $this->filingClaimedBy = null;
            $this->filingClaimedUntil = null;
        }
    }

    public function isHiddenFromClients(): bool
    {
        return $this->isSending();
    }

    /** The account changed: the copy filed in the old account's Drafts folder is someone else's job now. */
    public function forgetFiledCopy(): void
    {
        $this->filedVersion = 0;
        $this->filedMessageId = null;
        $this->filingClaimedBy = null;
        $this->filingClaimedUntil = null;
    }

    public function claimFiling(string $workerId, \DateTimeImmutable $until): void
    {
        $this->filingClaimedBy = $workerId;
        $this->filingClaimedUntil = $until;
    }

    public function isFilingClaimedBy(string $workerId, \DateTimeImmutable $now): bool
    {
        return $workerId === $this->filingClaimedBy && null !== $this->filingClaimedUntil && $this->filingClaimedUntil > $now;
    }

    /** A copy of $version is in the Drafts folder now, and the copy before it was removed. */
    public function filed(int $version, string $messageId): void
    {
        if ($version > $this->filedVersion) {
            $this->filedVersion = $version;
            $this->filedMessageId = $messageId;
        }
        $this->filingClaimedBy = null;
        $this->filingClaimedUntil = null;
    }

    public function releaseFiling(): void
    {
        $this->filingClaimedBy = null;
        $this->filingClaimedUntil = null;
    }

    public function getChangeOwner(): User
    {
        return $this->owner;
    }

    public static function clientFields(): ?array
    {
        return ['mailAccount', 'kind', 'recipients', 'subject', 'html', 'signature', 'quoteHtml', 'inReplyTo', 'version', 'updatedAt', 'sendingAs'];
    }

    public static function changeType(): string
    {
        return 'draft';
    }

    public function getChangeSeq(): int
    {
        return $this->changeSeq;
    }

    public function setChangeSeq(int $changeSeq): void
    {
        $this->changeSeq = $changeSeq;
    }
}
