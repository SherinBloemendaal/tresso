<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One delivery of one notification to one target (a device, or email). The unique delivery key and target are what
 * make delivery idempotent: a retried message or a sweep never notifies a device twice. The key is chosen by the
 * feature that sends, e.g. "calendar:{notification}:{round}". A failed send is tried again with a growing pause, and
 * one that never reported back (a crash) after a while; one that went out never is.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['delivery_key', 'target'])]
class NotificationDelivery
{
    public const string SENDING = 'sending';
    public const string SENT = 'sent';
    public const string FAILED = 'failed';
    public const string GONE = 'gone';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 190)]
    private string $deliveryKey;

    /** A device id, or "email". */
    #[ORM\Column(length: 64)]
    private string $target;

    #[ORM\Column(length: 16)]
    private string $status;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $error = null;

    /** Sends tried so far. */
    #[ORM\Column]
    private int $attempts = 1;

    /** Expo's ticket for a send, until its receipt was read. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $ticket = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $deliveryKey, string $target)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->deliveryKey = $deliveryKey;
        $this->target = $target;
        $this->status = self::SENDING;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDeliveryKey(): string
    {
        return $this->deliveryKey;
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getTicket(): ?string
    {
        return $this->ticket;
    }
}
