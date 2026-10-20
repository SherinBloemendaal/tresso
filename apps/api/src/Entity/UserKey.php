<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One version of a user's key, wrapped by a master key. The highest version is current; older ones only decrypt until
 * re-encryption has moved their data on. Read and written by {@see \App\Security\Crypto\UserKeys} only, in SQL, so
 * concurrent first uses agree on one key.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['owner_id', 'version'])]
class UserKey
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column]
    private int $version;

    /** "v1.<master key id>.<base64(nonce + ciphertext)>", additional data "tresso:user-key:<user id>:<version>". */
    #[ORM\Column(type: Types::TEXT)]
    private string $wrappedKey;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $owner, int $version, string $wrappedKey, \DateTimeImmutable $createdAt)
    {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->version = $version;
        $this->wrappedKey = $wrappedKey;
        $this->createdAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getWrappedKey(): string
    {
        return $this->wrappedKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
