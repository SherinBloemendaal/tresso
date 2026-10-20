<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SchedulingPreferencesRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * What the meeting time finder keeps free besides the working hours and zone of the notification settings: a buffer
 * around busy time, the travel time to busy time at a place that names none, and the notice the user needs. A user
 * without a row has none of them.
 */
#[ORM\Entity(repositoryClass: SchedulingPreferencesRepository::class)]
class SchedulingPreferences
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private int $bufferMinutes = 0;

    #[ORM\Column]
    private int $travelMinutes = 0;

    #[ORM\Column]
    private int $minimumNoticeMinutes = 0;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getBufferMinutes(): int
    {
        return $this->bufferMinutes;
    }

    public function setBufferMinutes(int $bufferMinutes): void
    {
        $this->bufferMinutes = $bufferMinutes;
        $this->touch();
    }

    public function getTravelMinutes(): int
    {
        return $this->travelMinutes;
    }

    public function setTravelMinutes(int $travelMinutes): void
    {
        $this->travelMinutes = $travelMinutes;
        $this->touch();
    }

    public function getMinimumNoticeMinutes(): int
    {
        return $this->minimumNoticeMinutes;
    }

    public function setMinimumNoticeMinutes(int $minimumNoticeMinutes): void
    {
        $this->minimumNoticeMinutes = $minimumNoticeMinutes;
        $this->touch();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
