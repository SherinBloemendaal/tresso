<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\HandOverState;
use App\Repository\HandOverRepository;
use App\Sync\Change\TracksChanges;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A conversation the user forwarded to someone with a note and a due date, and waits on until that person replies in
 * it or the user marks it done. The subject is kept, so the hand-over still reads well when its message is gone.
 */
#[ORM\Entity(repositoryClass: HandOverRepository::class)]
#[ORM\Index(columns: ['owner_id', 'change_seq'])]
#[ORM\Index(columns: ['mail_account_id', 'state', 'due_at'])]
class HandOver implements TracksChanges
{
    public const int MAX_NOTE = 10_000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $changeSeq = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MailAccount $mailAccount;

    /** The message that was handed over; its conversation is the one a reply is expected in. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Message $message;

    #[ORM\Column(length: 998)]
    private string $subject;

    /** Lower-cased. */
    #[ORM\Column(length: 320)]
    private string $delegateAddress;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $delegateName;

    /** Plain text, sent along with the forward. */
    #[ORM\Column(type: Types::TEXT)]
    private string $note;

    #[ORM\Column]
    private \DateTimeImmutable $dueAt;

    #[ORM\Column(length: 16, enumType: HandOverState::class)]
    private HandOverState $state = HandOverState::Open;

    /** The forward that handed it over. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?OutgoingMessage $forward;

    /** When the due date passed without a reply; set once, which is when the user is told. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $overdueAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $nudgedAt = null;

    /** The latest nudge; clients leave it out once it was cancelled. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?OutgoingMessage $lastNudge = null;

    /** The delegate's reply that closed it. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Message $reply = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Message $message,
        string $delegateAddress,
        ?string $delegateName,
        string $note,
        \DateTimeImmutable $dueAt,
        ?OutgoingMessage $forward,
        \DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7();
        $this->mailAccount = $message->getMailAccount();
        $this->owner = $this->mailAccount->getOwner();
        $this->message = $message;
        $this->subject = $message->getSubject();
        $this->delegateAddress = mb_strtolower($delegateAddress);
        $this->delegateName = $delegateName;
        $this->note = $note;
        $this->dueAt = $dueAt;
        $this->forward = $forward;
        $this->createdAt = $now;
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

    public function getMailAccount(): MailAccount
    {
        return $this->mailAccount;
    }

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getDelegateAddress(): string
    {
        return $this->delegateAddress;
    }

    public function getDelegateName(): ?string
    {
        return $this->delegateName;
    }

    public function getNote(): string
    {
        return $this->note;
    }

    public function getDueAt(): \DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function getState(): HandOverState
    {
        return $this->state;
    }

    public function isOpen(): bool
    {
        return HandOverState::Open === $this->state;
    }

    public function getForward(): ?OutgoingMessage
    {
        return $this->forward;
    }

    public function getOverdueAt(): ?\DateTimeImmutable
    {
        return $this->overdueAt;
    }

    public function getNudgedAt(): ?\DateTimeImmutable
    {
        return $this->nudgedAt;
    }

    public function getLastNudge(): ?OutgoingMessage
    {
        return $this->lastNudge;
    }

    public function getReply(): ?Message
    {
        return $this->reply;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** A new due date; once it passes without a reply the user is told again. */
    public function reschedule(\DateTimeImmutable $dueAt, \DateTimeImmutable $now): void
    {
        if ($dueAt == $this->dueAt) {
            return;
        }
        $this->dueAt = $dueAt;
        $this->overdueAt = null;
        $this->updatedAt = $now;
    }

    /** True when it just became overdue: open, past its due date, and not marked overdue before. */
    public function markOverdueIfDue(\DateTimeImmutable $now): bool
    {
        if (!$this->isOpen() || null !== $this->overdueAt || $this->dueAt > $now) {
            return false;
        }
        $this->overdueAt = $now;
        $this->updatedAt = $now;

        return true;
    }

    public function nudged(OutgoingMessage $nudge, \DateTimeImmutable $now): void
    {
        $this->lastNudge = $nudge;
        $this->nudgedAt = $now;
        $this->updatedAt = $now;
    }

    public function replied(Message $reply, \DateTimeImmutable $now): void
    {
        if (!$this->isOpen()) {
            return;
        }
        $this->state = HandOverState::Replied;
        $this->reply = $reply;
        $this->closedAt = $now;
        $this->updatedAt = $now;
    }

    public function markDone(\DateTimeImmutable $now): void
    {
        if (!$this->isOpen()) {
            return;
        }
        $this->state = HandOverState::Done;
        $this->closedAt = $now;
        $this->updatedAt = $now;
    }

    /** Back to waiting, for a hand-over closed by mistake. */
    public function reopen(\DateTimeImmutable $now): void
    {
        if ($this->isOpen()) {
            return;
        }
        $this->state = HandOverState::Open;
        $this->reply = null;
        $this->closedAt = null;
        $this->updatedAt = $now;
    }

    public function getChangeOwner(): User
    {
        return $this->owner;
    }

    public static function clientFields(): ?array
    {
        return ['message', 'subject', 'delegateAddress', 'delegateName', 'note', 'dueAt', 'state', 'forward', 'overdueAt', 'nudgedAt', 'lastNudge', 'reply', 'closedAt', 'updatedAt'];
    }

    public static function changeType(): string
    {
        return 'handOver';
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
