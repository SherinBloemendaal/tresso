<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BuiltinCategory;
use App\Enum\Verdict;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A smart inbox category. Built-in categories are seeded per user so their instructions stay editable.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['owner_id', 'builtin'])]
class Category
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 16, nullable: true, enumType: BuiltinCategory::class)]
    private ?BuiltinCategory $builtin;

    #[ORM\Column(length: 80)]
    private string $name;

    /** Natural-language description the AI uses to recognise this category. */
    #[ORM\Column(type: Types::TEXT)]
    private string $instructions;

    /**
     * Addresses or domains (`@example.com`) that always land in this category.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $includeSenders = [];

    /**
     * Addresses or domains that never land in this category.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $excludeSenders = [];

    #[ORM\Column(length: 16, enumType: Verdict::class)]
    private Verdict $verdict;

    #[ORM\Column]
    private bool $autoCreateTodo = false;

    /** Deliver in batches a few times a day instead of immediately. */
    #[ORM\Column]
    private bool $batchDelivery = false;

    #[ORM\Column]
    private bool $notify = true;

    #[ORM\Column]
    private int $position = 0;

    public function __construct(User $owner, string $name, string $instructions, Verdict $verdict, ?BuiltinCategory $builtin = null)
    {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->name = $name;
        $this->instructions = $instructions;
        $this->verdict = $verdict;
        $this->builtin = $builtin;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getBuiltin(): ?BuiltinCategory
    {
        return $this->builtin;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getInstructions(): string
    {
        return $this->instructions;
    }

    public function setInstructions(string $instructions): void
    {
        $this->instructions = $instructions;
    }

    /** @return list<string> */
    public function getIncludeSenders(): array
    {
        return $this->includeSenders;
    }

    /** @param list<string> $includeSenders */
    public function setIncludeSenders(array $includeSenders): void
    {
        $this->includeSenders = $includeSenders;
    }

    /** @return list<string> */
    public function getExcludeSenders(): array
    {
        return $this->excludeSenders;
    }

    /** @param list<string> $excludeSenders */
    public function setExcludeSenders(array $excludeSenders): void
    {
        $this->excludeSenders = $excludeSenders;
    }

    public function getVerdict(): Verdict
    {
        return $this->verdict;
    }

    public function setVerdict(Verdict $verdict): void
    {
        $this->verdict = $verdict;
    }

    public function isAutoCreateTodo(): bool
    {
        return $this->autoCreateTodo;
    }

    public function setAutoCreateTodo(bool $autoCreateTodo): void
    {
        $this->autoCreateTodo = $autoCreateTodo;
    }

    public function isBatchDelivery(): bool
    {
        return $this->batchDelivery;
    }

    public function setBatchDelivery(bool $batchDelivery): void
    {
        $this->batchDelivery = $batchDelivery;
    }

    public function isNotify(): bool
    {
        return $this->notify;
    }

    public function setNotify(bool $notify): void
    {
        $this->notify = $notify;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }
}
