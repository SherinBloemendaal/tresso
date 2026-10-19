<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cloud-synced preferences shared by every client of a user.
 */
#[ORM\Entity]
class UserSettings
{
    #[ORM\Id]
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $values = [];

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

    /** @return array<string, mixed> */
    public function getValues(): array
    {
        return $this->values;
    }

    /** @param array<string, mixed> $values */
    public function replaceValues(array $values): void
    {
        $this->values = $values;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
