<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MailAccountStatus;
use App\Enum\MailProvider;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['owner_id', 'email_address'])]
#[ORM\Index(columns: ['status', 'lease_expires_at'])]
class MailAccount
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 320)]
    private string $emailAddress;

    #[ORM\Column(length: 120)]
    private string $displayName;

    #[ORM\Column(length: 16, enumType: MailProvider::class)]
    private MailProvider $provider;

    /**
     * Server settings without secrets (IMAP/SMTP host, port, security).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $connection = [];

    /**
     * Credentials (password or OAuth tokens), encrypted with the owner's data key.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $encryptedCredentials = null;

    #[ORM\Column(length: 16, enumType: MailAccountStatus::class)]
    private MailAccountStatus $status = MailAccountStatus::Active;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $leasedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $leaseExpiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $owner, string $emailAddress, string $displayName, MailProvider $provider)
    {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->emailAddress = mb_strtolower($emailAddress);
        $this->displayName = $displayName;
        $this->provider = $provider;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getEmailAddress(): string
    {
        return $this->emailAddress;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getProvider(): MailProvider
    {
        return $this->provider;
    }

    /** @return array<string, mixed> */
    public function getConnection(): array
    {
        return $this->connection;
    }

    /** @param array<string, mixed> $connection */
    public function setConnection(array $connection): void
    {
        $this->connection = $connection;
    }

    public function getEncryptedCredentials(): ?string
    {
        return $this->encryptedCredentials;
    }

    public function setEncryptedCredentials(?string $encryptedCredentials): void
    {
        $this->encryptedCredentials = $encryptedCredentials;
    }

    public function getStatus(): MailAccountStatus
    {
        return $this->status;
    }

    public function setStatus(MailAccountStatus $status): void
    {
        $this->status = $status;
    }

    public function getLeasedBy(): ?string
    {
        return $this->leasedBy;
    }

    public function getLeaseExpiresAt(): ?\DateTimeImmutable
    {
        return $this->leaseExpiresAt;
    }

    public function getLastSyncedAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncedAt;
    }

    public function markSynced(\DateTimeImmutable $at): void
    {
        $this->lastSyncedAt = $at;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
