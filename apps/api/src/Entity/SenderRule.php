<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Verdict;
use App\Repository\SenderRuleRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A correction the user made, remembered for the sender: mail from them goes to this smart inbox, with this verdict
 * when one is set (otherwise the smart inbox's verdict). Classification checks these before anything else.
 */
#[ORM\Entity(repositoryClass: SenderRuleRepository::class)]
#[ORM\UniqueConstraint(columns: ['owner_id', 'sender'])]
class SenderRule
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    /** An address, or `@domain` for a whole domain. */
    #[ORM\Column(length: 320)]
    private string $sender;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Category $category;

    #[ORM\Column(length: 16, nullable: true, enumType: Verdict::class)]
    private ?Verdict $verdict;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $owner, string $sender, Category $category, ?Verdict $verdict)
    {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->sender = mb_strtolower($sender);
        $this->category = $category;
        $this->verdict = $verdict;
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

    public function getSender(): string
    {
        return $this->sender;
    }

    public function getCategory(): Category
    {
        return $this->category;
    }

    /** null: the smart inbox's verdict. */
    public function getVerdict(): ?Verdict
    {
        return $this->verdict;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function change(Category $category, ?Verdict $verdict): void
    {
        $this->category = $category;
        $this->verdict = $verdict;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
