<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OAuthProvider;
use App\Repository\ProviderGrantRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * What one user allowed Tresso to do with one account at an identity provider (a Google account, a Microsoft work or
 * personal account): the scopes granted so far and the tokens, sealed with the owner's data key.
 *
 * One grant serves every feature that uses that provider account. Mail accounts link to it now; calendars will link
 * to the same grant and add their scopes through incremental consent. Only the API ever reads the refresh token: sync
 * workers get short-lived access tokens through the credentials endpoint.
 */
#[ORM\Entity(repositoryClass: ProviderGrantRepository::class)]
#[ORM\UniqueConstraint(columns: ['owner_id', 'provider', 'subject'])]
class ProviderGrant
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 16, enumType: OAuthProvider::class)]
    private OAuthProvider $provider;

    /** The provider's stable account id: Google "sub", Microsoft "<tenant id>:<object id>". */
    #[ORM\Column(length: 255)]
    private string $subject;

    /** The address the provider reported when the user last signed in. */
    #[ORM\Column(length: 320)]
    private string $emailAddress;

    /**
     * Scopes the provider confirmed, normalized by {@see \App\ProviderAuth\ProviderScopes::normalize()}.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $scopes = [];

    /** Sealed {"refreshToken", "accessToken", "accessTokenExpiresAt", "accessTokenIssuedAt"}. */
    #[ORM\Column(type: Types::TEXT)]
    private string $encryptedTokens = '';

    /** Set when the provider refused the refresh token: the user has to sign in again. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $owner, OAuthProvider $provider, string $subject, string $emailAddress)
    {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->provider = $provider;
        $this->subject = $subject;
        $this->emailAddress = mb_strtolower($emailAddress);
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getProvider(): OAuthProvider
    {
        return $this->provider;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getEmailAddress(): string
    {
        return $this->emailAddress;
    }

    /** @return list<string> */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    /**
     * @param list<string> $required
     */
    public function hasScopes(array $required): bool
    {
        return [] === array_diff($required, $this->scopes);
    }

    public function getEncryptedTokens(): string
    {
        return $this->encryptedTokens;
    }

    public function setEncryptedTokens(string $encryptedTokens): void
    {
        $this->encryptedTokens = $encryptedTokens;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * A successful sign-in: the provider confirmed these scopes and issued new tokens (sealed separately).
     *
     * @param list<string> $scopes
     */
    public function authorize(string $emailAddress, array $scopes): void
    {
        $this->emailAddress = mb_strtolower($emailAddress);
        $this->scopes = $scopes;
        $this->revokedAt = null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @param list<string> $scopes
     */
    public function updateScopes(array $scopes): void
    {
        $this->scopes = $scopes;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isRevoked(): bool
    {
        return null !== $this->revokedAt;
    }

    public function revoke(): void
    {
        $this->revokedAt = new \DateTimeImmutable();
        $this->updatedAt = $this->revokedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
