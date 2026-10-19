<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MailAccountStatus;
use App\Enum\MailProvider;
use App\Mail\ServerSettings;
use App\Repository\MailAccountRepository;
use Doctrine\DBAL\Types\Types;
use App\Sync\Change\TracksChanges;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: MailAccountRepository::class)]
#[ORM\UniqueConstraint(columns: ['owner_id', 'email_address'])]
#[ORM\Index(columns: ['status', 'lease_expires_at'])]
#[ORM\Index(columns: ['owner_id', 'change_seq'])]
class MailAccount implements TracksChanges
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $changeSeq = 0;

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
     * Server settings without secrets: {"imap": {...}, "smtp": {...}, "username": "..."}.
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

    /** Why the account is not syncing, in words the user can act on. */
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $statusDetail = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $leasedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $leaseExpiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSyncedAt = null;

    /** Inserted into new messages from this account. Always one of the owner's own signatures. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Signature $defaultSignature = null;

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

    public function setDisplayName(string $displayName): void
    {
        $this->displayName = $displayName;
    }

    public function configureServers(ServerSettings $imap, ServerSettings $smtp, string $username): void
    {
        $this->connection = ['imap' => $imap->toArray(), 'smtp' => $smtp->toArray(), 'username' => $username];
    }

    public function getImap(): ServerSettings
    {
        return ServerSettings::fromArray($this->connectionPart('imap'));
    }

    public function getSmtp(): ServerSettings
    {
        return ServerSettings::fromArray($this->connectionPart('smtp'));
    }

    public function getUsername(): string
    {
        $username = $this->connection['username'] ?? null;

        return \is_string($username) ? $username : throw new \UnexpectedValueException('The mail account has no username.');
    }

    /**
     * Connection settings as the sync workers receive them.
     *
     * @return array<string, mixed>
     */
    public function getConnection(): array
    {
        return $this->connection;
    }

    /** @return array<mixed> */
    private function connectionPart(string $part): array
    {
        $value = $this->connection[$part] ?? null;

        return \is_array($value) ? $value : throw new \UnexpectedValueException(\sprintf('The mail account has no %s settings.', $part));
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

    public function setStatus(MailAccountStatus $status, ?string $detail = null): void
    {
        $this->status = $status;
        $this->statusDetail = MailAccountStatus::Active === $status || MailAccountStatus::Paused === $status ? null : $detail;
    }

    public function getStatusDetail(): ?string
    {
        return $this->statusDetail;
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

    public function getDefaultSignature(): ?Signature
    {
        return $this->defaultSignature;
    }

    public function setDefaultSignature(?Signature $signature): void
    {
        if (null !== $signature && $signature->getOwner() !== $this->owner) {
            throw new \InvalidArgumentException('A mail account can only use a signature of its own owner.');
        }

        $this->defaultSignature = $signature;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getChangeOwner(): User
    {
        return $this->owner;
    }

    public static function clientFields(): ?array
    {
        return ['emailAddress', 'displayName', 'provider', 'status', 'statusDetail', 'lastSyncedAt', 'defaultSignature'];
    }

    public static function changeType(): string
    {
        return 'mailAccount';
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
