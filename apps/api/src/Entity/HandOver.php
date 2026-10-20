<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\HandOverState;
use App\Repository\HandOverRepository;
use App\Sync\Change\TracksChanges;
use App\Sync\Ingest\MessageIds;
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
#[ORM\UniqueConstraint(columns: ['forward_id'])]
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

    /**
     * The conversation it was handed over in, followed through merges; it still finds a reply once the message handed
     * over is gone.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Thread $thread;

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

    /** The forward's Message-ID (stored form), which a reply to it refers to, whatever became of the conversation. */
    #[ORM\Column(length: 998, nullable: true)]
    private ?string $forwardMessageId;

    /** When the due date passed without a reply; set once, which is when the user is told. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $overdueAt = null;

    /** When the overdue notice is due: the moment it became overdue, later while a focus block holds it. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $notifyAt = null;

    /** When the server delivered the overdue notice, on {@see $notifiedChannels}; clients show it from the delta. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $notifiedAt = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $notifiedChannels = [];

    /** Delivered in quiet hours or while the user was busy: no sound. */
    #[ORM\Column]
    private bool $notifiedSilently = false;

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
        $this->thread = $message->getThread();
        $this->subject = $message->getSubject();
        $this->delegateAddress = mb_strtolower($delegateAddress);
        $this->delegateName = $delegateName;
        $this->note = $note;
        $this->dueAt = self::utc($dueAt);
        $this->forward = $forward;
        $this->forwardMessageId = MessageIds::normalize($forward?->getMessageIdHeader());
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

    public function getThread(): ?Thread
    {
        return $this->thread;
    }

    public function moveToThread(Thread $thread): void
    {
        $this->thread = $thread;
    }

    public function getForwardMessageId(): ?string
    {
        return $this->forwardMessageId;
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
        $dueAt = self::utc($dueAt);
        if ($dueAt == $this->dueAt) {
            return;
        }
        $this->dueAt = $dueAt;
        $this->overdueAt = null;
        $this->notifyAt = null;
        $this->notifiedAt = null;
        $this->notifiedChannels = [];
        $this->notifiedSilently = false;
        $this->updatedAt = $now;
    }

    /** True when it just became overdue: open, past its due date, and not marked overdue before. */
    public function markOverdueIfDue(\DateTimeImmutable $now): bool
    {
        if (!$this->isOpen() || null !== $this->overdueAt || $this->dueAt > $now) {
            return false;
        }
        $this->overdueAt = $now;
        $this->notifyAt = $now;
        $this->updatedAt = $now;

        return true;
    }

    public function getNotifyAt(): ?\DateTimeImmutable
    {
        return $this->notifyAt;
    }

    /** A focus block holds the overdue notice until it ends. */
    public function postponeNotice(\DateTimeImmutable $until): void
    {
        $this->notifyAt = $until;
    }

    /**
     * The overdue notice went out (or, with no channels chosen, was decided against): clients show it from the delta.
     *
     * @param list<string> $channels
     */
    public function noticeDelivered(\DateTimeImmutable $at, array $channels, bool $silent): void
    {
        $this->notifiedAt = $at;
        $this->notifiedChannels = $channels;
        $this->notifiedSilently = $silent;
        $this->updatedAt = $at;
    }

    public function getNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->notifiedAt;
    }

    /** @return list<string> */
    public function getNotifiedChannels(): array
    {
        return $this->notifiedChannels;
    }

    public function isNotifiedSilently(): bool
    {
        return $this->notifiedSilently;
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

    /** Stored without a zone, so always in UTC: the zone a client sent is not kept. */
    private static function utc(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'));
    }

    public function getChangeOwner(): User
    {
        return $this->owner;
    }

    public static function clientFields(): ?array
    {
        return ['message', 'thread', 'subject', 'delegateAddress', 'delegateName', 'note', 'dueAt', 'state', 'forward', 'overdueAt', 'notifiedAt', 'notifiedChannels', 'notifiedSilently', 'nudgedAt', 'lastNudge', 'reply', 'closedAt', 'updatedAt'];
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
