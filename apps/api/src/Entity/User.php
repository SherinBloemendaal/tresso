<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 320, unique: true)]
    private string $email;

    #[ORM\Column(length: 255)]
    private string $password = '';

    #[ORM\Column(length: 120)]
    private string $displayName;

    /**
     * The data key from before user keys, wrapped by a master key. {@see \App\Security\Crypto\UserKeys} turns it into
     * key version 1 on first use and clears it; the column goes once no user has one left.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $wrappedDataKey = null;

    /**
     * Names content (fingerprints and object names), wrapped by a master key; never rotated with the user keys. Read
     * and written by {@see \App\Security\Crypto\UserKeys} only, in SQL.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $wrappedIndexKey = null;

    /**
     * The key version all of the user's stored data is on, as far as re-encryption knows; null until it has looked.
     * Behind the current version, or older versions still kept, means the background re-encryption has work.
     */
    #[ORM\Column(nullable: true)]
    private ?int $dataKeyVersion = null;

    /** When the user's keys were shredded (they are being deleted); keys are never created for them again. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $keysShreddedAt = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    /** Last change number handed out for this user's synced data; only ChangeSequencer writes it. */
    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $changeSeq = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email, string $displayName)
    {
        $this->id = Uuid::v7();
        $this->email = mb_strtolower($email);
        $this->displayName = $displayName;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): void
    {
        $this->displayName = $displayName;
    }

    public function getUserIdentifier(): string
    {
        \assert('' !== $this->email);

        return $this->email;
    }

    public function getRoles(): array
    {
        return array_values(array_unique([...$this->roles, 'ROLE_USER']));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): void
    {
        $this->roles = $roles;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    public function getKeysShreddedAt(): ?\DateTimeImmutable
    {
        return $this->keysShreddedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getChangeSeq(): int
    {
        return $this->changeSeq;
    }

    /**
     * Only for a user that is being inserted in the current flush: nobody else can see the row yet, so the counter
     * can advance in memory. Existing users get their numbers from ChangeSequencer.
     */
    public function nextChangeSeqForNewUser(): int
    {
        return ++$this->changeSeq;
    }
}
