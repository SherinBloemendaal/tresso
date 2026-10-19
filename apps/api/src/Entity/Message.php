<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Verdict;
use App\Repository\MessageRepository;
use Doctrine\DBAL\Types\Types;
use App\Sync\Change\TracksChanges;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Message metadata. The raw MIME lives in object storage under $rawObjectKey.
 *
 * A message can live in several folders at once (Gmail labels, IMAP copies); see MessageLocation.
 */
#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Index(columns: ['mail_account_id', 'received_at'])]
#[ORM\Index(columns: ['mail_account_id', 'message_id_header'])]
#[ORM\Index(columns: ['thread_id'])]
#[ORM\Index(columns: ['mail_account_id', 'change_seq'])]
#[ORM\Index(name: 'message_unclassified', columns: ['mail_account_id', 'received_at'], options: ['where' => '(classified_at IS NULL)'])]
class Message implements TracksChanges
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $changeSeq = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MailAccount $mailAccount;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Thread $thread;

    #[ORM\Column(length: 998, nullable: true)]
    private ?string $messageIdHeader;

    #[ORM\Column(length: 998, nullable: true)]
    private ?string $inReplyTo = null;

    #[ORM\Column(length: 998)]
    private string $subject;

    #[ORM\Column(length: 320)]
    private string $fromAddress;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $fromName;

    /**
     * Recipients per header, e.g. ['to' => [['address' => 'a@b.c', 'name' => 'A']], 'cc' => [...]].
     *
     * @var array<'to'|'cc'|'bcc'|'replyTo', list<array{address: string, name: ?string}>>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $recipients = [];

    #[ORM\Column(length: 512)]
    private string $snippet = '';

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $receivedAt;

    #[ORM\Column]
    private bool $seen = false;

    #[ORM\Column]
    private bool $flagged = false;

    #[ORM\Column]
    private bool $answered = false;

    #[ORM\Column]
    private bool $draft = false;

    #[ORM\Column]
    private bool $hasAttachments = false;

    #[ORM\Column(type: Types::BIGINT)]
    private int $size = 0;

    #[ORM\Column(length: 255)]
    private string $rawObjectKey;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Category $category = null;

    #[ORM\Column(length: 16, nullable: true, enumType: Verdict::class)]
    private ?Verdict $verdict = null;

    /** One-line AI summary, used in the daily brief and in delegation notes. */
    #[ORM\Column(length: 512, nullable: true)]
    private ?string $summary = null;

    /** When classification looked at this message; null while it waits for its turn. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $classifiedAt = null;

    public function __construct(
        MailAccount $mailAccount,
        Thread $thread,
        ?string $messageIdHeader,
        string $subject,
        string $fromAddress,
        ?string $fromName,
        \DateTimeImmutable $receivedAt,
        string $rawObjectKey,
    ) {
        $this->id = Uuid::v7();
        $this->mailAccount = $mailAccount;
        $this->thread = $thread;
        $this->messageIdHeader = $messageIdHeader;
        $this->subject = $subject;
        $this->fromAddress = mb_strtolower($fromAddress);
        $this->fromName = $fromName;
        $this->receivedAt = $receivedAt;
        $this->rawObjectKey = $rawObjectKey;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMailAccount(): MailAccount
    {
        return $this->mailAccount;
    }

    public function getThread(): Thread
    {
        return $this->thread;
    }

    /** Used when two partial threads turn out to be one conversation. */
    public function moveToThread(Thread $thread): void
    {
        $this->thread = $thread;
    }

    public function getMessageIdHeader(): ?string
    {
        return $this->messageIdHeader;
    }

    public function getInReplyTo(): ?string
    {
        return $this->inReplyTo;
    }

    public function setInReplyTo(?string $inReplyTo): void
    {
        $this->inReplyTo = $inReplyTo;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getFromAddress(): string
    {
        return $this->fromAddress;
    }

    public function getFromName(): ?string
    {
        return $this->fromName;
    }

    /** @return array<'to'|'cc'|'bcc'|'replyTo', list<array{address: string, name: ?string}>> */
    public function getRecipients(): array
    {
        return $this->recipients;
    }

    /** @param array<'to'|'cc'|'bcc'|'replyTo', list<array{address: string, name: ?string}>> $recipients */
    public function setRecipients(array $recipients): void
    {
        $this->recipients = $recipients;
    }

    public function getSnippet(): string
    {
        return $this->snippet;
    }

    public function setSnippet(string $snippet): void
    {
        $this->snippet = mb_substr($snippet, 0, 512);
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): void
    {
        $this->sentAt = $sentAt;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function isSeen(): bool
    {
        return $this->seen;
    }

    public function isFlagged(): bool
    {
        return $this->flagged;
    }

    public function isAnswered(): bool
    {
        return $this->answered;
    }

    public function isDraft(): bool
    {
        return $this->draft;
    }

    public function updateFlags(bool $seen, bool $flagged, bool $answered, bool $draft): void
    {
        $this->seen = $seen;
        $this->flagged = $flagged;
        $this->answered = $answered;
        $this->draft = $draft;
    }

    public function hasAttachments(): bool
    {
        return $this->hasAttachments;
    }

    public function setHasAttachments(bool $hasAttachments): void
    {
        $this->hasAttachments = $hasAttachments;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): void
    {
        $this->size = $size;
    }

    public function getRawObjectKey(): string
    {
        return $this->rawObjectKey;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function getVerdict(): ?Verdict
    {
        return $this->verdict;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function getClassifiedAt(): ?\DateTimeImmutable
    {
        return $this->classifiedAt;
    }

    /** The smart inbox and verdict classification gave this message. The thread takes those of its newest one. */
    public function classify(Category $category, Verdict $verdict, \DateTimeImmutable $at): void
    {
        $this->category = $category;
        $this->verdict = $verdict;
        $this->classifiedAt = $at;
    }

    public function getChangeOwner(): User
    {
        return $this->mailAccount->getOwner();
    }

    public static function clientFields(): ?array
    {
        return null;
    }

    public static function changeType(): string
    {
        return 'message';
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
