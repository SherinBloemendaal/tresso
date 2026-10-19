<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One delivery of a notification to one target (a device, or email) in one round. The unique key is what makes
 * delivery idempotent: a retried message or a sweep never notifies a device twice.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['notification_id', 'target', 'round'])]
class NotificationDelivery
{
    public const string SENT = 'sent';
    public const string FAILED = 'failed';
    public const string GONE = 'gone';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ScheduledNotification $notification;

    /** A device id, or "email". */
    #[ORM\Column(length: 64)]
    private string $target;

    #[ORM\Column]
    private int $round;

    #[ORM\Column(length: 16)]
    private string $status;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $error;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(ScheduledNotification $notification, string $target, string $status, ?string $error = null)
    {
        $this->id = Uuid::v7();
        $this->notification = $notification;
        $this->target = $target;
        $this->round = $notification->getRound();
        $this->status = $status;
        $this->error = null === $error ? null : mb_substr($error, 0, 500);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getNotification(): ScheduledNotification
    {
        return $this->notification;
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function getRound(): int
    {
        return $this->round;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getError(): ?string
    {
        return $this->error;
    }
}
