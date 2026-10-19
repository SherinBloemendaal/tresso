<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SignatureRepository;
use App\Sync\Change\TracksChanges;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * HTML signature, sanitized before it is stored. Mail accounts point at the one they insert by default
 * ({@see MailAccount::getDefaultSignature()}), so one signature can be the default of several accounts.
 */
#[ORM\Entity(repositoryClass: SignatureRepository::class)]
#[ORM\Index(columns: ['owner_id', 'change_seq'])]
class Signature implements TracksChanges
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $changeSeq = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: Types::TEXT)]
    private string $html;

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

    public function getName(): string
    {
        return $this->name;
    }

    public function getHtml(): string
    {
        return $this->html;
    }

    public function update(string $name, string $html): void
    {
        if ($name === $this->name && $html === $this->html) {
            return;
        }

        $this->name = $name;
        $this->html = $html;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getChangeOwner(): User
    {
        return $this->owner;
    }

    public static function clientFields(): ?array
    {
        return ['name', 'html', 'updatedAt'];
    }

    public static function changeType(): string
    {
        return 'signature';
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
