<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * The user's last known position, shared by a device only when the user allows it, for leave-now reminders. Kept as
 * one coarse point (about 100 m) with its time; nothing else is kept.
 */
#[ORM\Entity]
class UserLocation
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column]
    private float $latitude;

    #[ORM\Column]
    private float $longitude;

    #[ORM\Column]
    private \DateTimeImmutable $recordedAt;

    public function __construct(User $user, float $latitude, float $longitude, \DateTimeImmutable $recordedAt)
    {
        $this->user = $user;
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        $this->recordedAt = $recordedAt;
        $this->move($latitude, $longitude, $recordedAt);
    }

    public function move(float $latitude, float $longitude, \DateTimeImmutable $recordedAt): void
    {
        // Three decimals is about 100 m: enough for travel time, not more than needed.
        $this->latitude = round($latitude, 3);
        $this->longitude = round($longitude, 3);
        $this->recordedAt = $recordedAt;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getLatitude(): float
    {
        return $this->latitude;
    }

    public function getLongitude(): float
    {
        return $this->longitude;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
