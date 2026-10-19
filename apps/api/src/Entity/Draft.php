<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DraftKind;
use App\Repository\DraftRepository;
use App\Sync\Change\TracksChanges;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A message being written, the same on every device. Each save raises the version; a save based on an older version
 * is refused, so two devices never silently overwrite each other. The worker that leases the account files a copy
 * in its Drafts folder (filing state below is internal), so other mail apps see the draft too.
 */
#[ORM\Entity(repositoryClass: DraftRepository::class)]
#[ORM\Index(columns: ['owner_id', 'change_seq'])]
#[ORM\Index(columns: ['mail_account_id', 'filed_version'])]
class Draft implements TracksChanges
{
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
    }

    /** Its signature was deleted. A change clients see, but not a new version of what the user wrote. */
    public function dropSignature(): void
    {
        $this->signature = null;
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
        return ['mailAccount', 'kind', 'recipients', 'subject', 'html', 'signature', 'quoteHtml', 'inReplyTo', 'version', 'updatedAt'];
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
