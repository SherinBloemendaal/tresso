<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * HTML signature. When $mailAccount is set and $isDefault is true it is inserted for that account automatically.
 */
#[ORM\Entity]
#[ORM\Index(columns: ['owner_id'])]
class Signature
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?MailAccount $mailAccount = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: Types::TEXT)]
    private string $html;

    #[ORM\Column]
    private bool $isDefault = false;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $owner, string $name, string $html)
    {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->name = $name;
        $this->html = $html;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getMailAccount(): ?MailAccount
    {
        return $this->mailAccount;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getHtml(): string
    {
        return $this->html;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function update(string $name, string $html, ?MailAccount $mailAccount, bool $isDefault): void
    {
        $this->name = $name;
        $this->html = $html;
        $this->mailAccount = $mailAccount;
        $this->isDefault = $isDefault;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
