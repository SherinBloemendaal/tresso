<?php

declare(strict_types=1);

namespace App\Entity;

use App\Batch\BatchTimes;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * When the user's batches go out: mail of smart inboxes that deliver in batches waits for the next of these times, in
 * the user's time zone. A user without a row has the defaults of {@see BatchTimes}.
 */
#[ORM\Entity]
class BatchSchedule
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /**
     * Times of day as "HH:MM", sorted, without duplicates.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $times;

    #[ORM\Column(length: 64)]
    private string $timeZone;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @param list<string> $times */
    public function __construct(User $user, array $times, string $timeZone)
    {
        $this->user = $user;
        $this->times = BatchTimes::normalize($times);
        $this->timeZone = $timeZone;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /** @return list<string> */
    public function getTimes(): array
    {
        return $this->times;
    }

    public function getTimeZone(): string
    {
        return $this->timeZone;
    }

    /** @param list<string> $times */
    public function change(array $times, string $timeZone): void
    {
        $this->times = BatchTimes::normalize($times);
        $this->timeZone = $timeZone;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function batchTimes(): BatchTimes
    {
        return new BatchTimes($this->times, new \DateTimeZone($this->timeZone));
    }
}
