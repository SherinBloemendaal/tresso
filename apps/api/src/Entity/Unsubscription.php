<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\UnsubscribeMethod;
use App\Enum\UnsubscribeStatus;
use App\Sync\Change\TracksChanges;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * The user's request to stop mail from a sender to one of their accounts, and what became of it. One per account and
 * sender (the lower-cased From address): clients stop offering the button for that sender once it is pending or done,
 * and offer it again after a failure. A one-click request is made by the worker that leases the account (claimed like
 * a mailbox change); a mailto request is a mail in the outbox ($outgoingMessage) and follows what becomes of it.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['mail_account_id', 'sender'])]
#[ORM\Index(columns: ['mail_account_id', 'change_seq'])]
class Unsubscription implements TracksChanges
{
    public const int MAX_ATTEMPTS = 6;
    public const int ERROR_MAX_LENGTH = 500;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $changeSeq = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MailAccount $mailAccount;

    #[ORM\Column(length: 320)]
    private string $sender;

    /** The message the user unsubscribed from. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Message $message;

    #[ORM\Column(length: 16, enumType: UnsubscribeMethod::class)]
    private UnsubscribeMethod $method;

    /** The HTTPS address to POST to, or the mailto: address. */
    #[ORM\Column(length: 2048)]
    private string $uri;

    #[ORM\Column(length: 16, enumType: UnsubscribeStatus::class)]
    private UnsubscribeStatus $status = UnsubscribeStatus::Pending;

    #[ORM\Column(length: self::ERROR_MAX_LENGTH, nullable: true)]
    private ?string $error = null;

    /** The mail a mailto request sent. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?OutgoingMessage $outgoingMessage = null;

    #[ORM\Column]
    private int $attempts = 0;

    /** Not offered to a worker before this moment (backoff after a failed attempt). */
    #[ORM\Column]
    private \DateTimeImmutable $notBefore;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $claimedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $claimedUntil = null;

    #[ORM\Column]
    private \DateTimeImmutable $requestedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct(MailAccount $mailAccount, string $sender, Message $message, UnsubscribeMethod $method, string $uri, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->mailAccount = $mailAccount;
        $this->sender = mb_strtolower($sender);
        $this->message = $message;
        $this->method = $method;
        $this->uri = $uri;
        $this->requestedAt = $now;
        $this->notBefore = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMailAccount(): MailAccount
    {
        return $this->mailAccount;
    }

    public function getSender(): string
    {
        return $this->sender;
    }

    public function getMethod(): UnsubscribeMethod
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getStatus(): UnsubscribeStatus
    {
        return $this->status;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getOutgoingMessage(): ?OutgoingMessage
    {
        return $this->outgoingMessage;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /** Asks again after a failure, possibly from a newer message with another address. */
    public function retry(Message $message, UnsubscribeMethod $method, string $uri, \DateTimeImmutable $now): void
    {
        $this->message = $message;
        $this->method = $method;
        $this->uri = $uri;
        $this->status = UnsubscribeStatus::Pending;
        $this->error = null;
        $this->attempts = 0;
        $this->notBefore = $now;
        $this->claimedBy = null;
        $this->claimedUntil = null;
        $this->outgoingMessage = null;
        $this->requestedAt = $now;
        $this->completedAt = null;
    }

    public function sentAs(OutgoingMessage $outgoingMessage): void
    {
        $this->outgoingMessage = $outgoingMessage;
    }

    public function claim(string $workerId, \DateTimeImmutable $until): void
    {
        $this->claimedBy = $workerId;
        $this->claimedUntil = $until;
    }

    public function isClaimedBy(string $workerId, \DateTimeImmutable $now): bool
    {
        return UnsubscribeStatus::Pending === $this->status && $workerId === $this->claimedBy && null !== $this->claimedUntil && $this->claimedUntil > $now;
    }

    public function done(\DateTimeImmutable $now): void
    {
        $this->status = UnsubscribeStatus::Done;
        $this->error = null;
        $this->completedAt = $now;
        $this->claimedBy = null;
        $this->claimedUntil = null;
    }

    public function fail(string $error, \DateTimeImmutable $now): void
    {
        $this->status = UnsubscribeStatus::Failed;
        $this->error = mb_substr($error, 0, self::ERROR_MAX_LENGTH);
        $this->completedAt = $now;
        $this->claimedBy = null;
        $this->claimedUntil = null;
    }

    /** A failure that may pass: offered again at $at, or failed for good after too many attempts. */
    public function retryAt(\DateTimeImmutable $at, string $error, \DateTimeImmutable $now): void
    {
        ++$this->attempts;
        if ($this->attempts >= self::MAX_ATTEMPTS) {
            $this->fail($error, $now);

            return;
        }
        $this->notBefore = $at;
        $this->claimedBy = null;
        $this->claimedUntil = null;
    }

    public function getChangeOwner(): User
    {
        return $this->mailAccount->getOwner();
    }

    public static function clientFields(): ?array
    {
        return ['sender', 'method', 'status', 'error', 'requestedAt', 'completedAt'];
    }

    public static function changeType(): string
    {
        return 'unsubscription';
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
