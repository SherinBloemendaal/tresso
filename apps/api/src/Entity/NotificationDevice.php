<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DeviceKind;
use App\Repository\NotificationDeviceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A device of the user that gets notifications: a browser subscription, a phone's Expo push token or a desktop app.
 */
#[ORM\Entity(repositoryClass: NotificationDeviceRepository::class)]
#[ORM\UniqueConstraint(columns: ['endpoint'])]
class NotificationDevice
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, enumType: DeviceKind::class)]
    private DeviceKind $kind;

    /** The push service URL, the Expo push token, or the desktop app's installation id. */
    #[ORM\Column(length: 2048)]
    private string $endpoint;

    /** Web push: the subscription's P-256 public key and auth secret (base64url). */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $publicKey = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $authSecret = null;

    #[ORM\Column(length: 120)]
    private string $name;

    /** The user can mute a device without signing it out. */
    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastSeenAt;

    public function __construct(User $user, DeviceKind $kind, string $endpoint, string $name)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->kind = $kind;
        $this->endpoint = $endpoint;
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->lastSeenAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getKind(): DeviceKind
    {
        return $this->kind;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getPublicKey(): ?string
    {
        return $this->publicKey;
    }

    public function getAuthSecret(): ?string
    {
        return $this->authSecret;
    }

    public function setWebPushKeys(?string $publicKey, ?string $authSecret): void
    {
        $this->publicKey = $publicKey;
        $this->authSecret = $authSecret;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function seen(\DateTimeImmutable $at): void
    {
        $this->lastSeenAt = $at;
    }
}
