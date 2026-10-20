<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DailyBriefRepository;
use App\Sync\Change\TracksChanges;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One edition of the daily brief: the conversations judged `know` in a period, as they stood when it was made. Clients
 * show them from their own copy of the mail (subject, sender, snippet, smart inbox), so the brief keeps only their
 * ids; whether one is noted follows from the conversation (out of the inbox once noted).
 */
#[ORM\Entity(repositoryClass: DailyBriefRepository::class)]
#[ORM\Index(columns: ['owner_id', 'change_seq'])]
#[ORM\Index(columns: ['owner_id', 'covers_until'])]
class DailyBrief implements TracksChanges
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    private int $changeSeq = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    /** Mail classified after this moment... */
    #[ORM\Column]
    private \DateTimeImmutable $coversFrom;

    /** ...up to and including this one. */
    #[ORM\Column]
    private \DateTimeImmutable $coversUntil;

    /**
     * Newest conversation first.
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $threadIds;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** When the email digest went out; null when it was not sent (yet). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailedAt = null;

    /**
     * @param list<string> $threadIds
     */
    public function __construct(User $owner, \DateTimeImmutable $coversFrom, \DateTimeImmutable $coversUntil, array $threadIds, \DateTimeImmutable $createdAt)
    {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->coversFrom = $coversFrom;
        $this->coversUntil = $coversUntil;
        $this->threadIds = $threadIds;
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

    public function getCoversFrom(): \DateTimeImmutable
    {
        return $this->coversFrom;
    }

    public function getCoversUntil(): \DateTimeImmutable
    {
        return $this->coversUntil;
    }

    /**
     * @return list<string>
     */
    public function getThreadIds(): array
    {
        return $this->threadIds;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getEmailedAt(): ?\DateTimeImmutable
    {
        return $this->emailedAt;
    }

    public function emailed(\DateTimeImmutable $at): void
    {
        $this->emailedAt = $at;
    }

    public function getChangeOwner(): User
    {
        return $this->owner;
    }

    public static function clientFields(): ?array
    {
        return ['coversFrom', 'coversUntil', 'threadIds', 'createdAt'];
    }

    public static function changeType(): string
    {
        return 'dailyBrief';
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
