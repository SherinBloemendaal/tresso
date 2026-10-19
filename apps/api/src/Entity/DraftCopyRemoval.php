<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DraftCopyRemovalRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A copy of a draft that must leave an account's Drafts folder: the draft was sent, deleted, or moved to another
 * account. Kept until the worker that leases the account has removed it.
 */
#[ORM\Entity(repositoryClass: DraftCopyRemovalRepository::class)]
#[ORM\Index(columns: ['mail_account_id'])]
class DraftCopyRemoval
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MailAccount $mailAccount;

    /** Without angle brackets. */
    #[ORM\Column(length: 998)]
    private string $messageId;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $claimedBy = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $claimedUntil = null;

    public function __construct(MailAccount $mailAccount, string $messageId)
    {
        $this->id = Uuid::v7();
        $this->mailAccount = $mailAccount;
        $this->messageId = $messageId;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMailAccount(): MailAccount
    {
        return $this->mailAccount;
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    public function claim(string $workerId, \DateTimeImmutable $until): void
    {
        $this->claimedBy = $workerId;
        $this->claimedUntil = $until;
    }

    public function isClaimedBy(string $workerId, \DateTimeImmutable $now): bool
    {
        return $workerId === $this->claimedBy && null !== $this->claimedUntil && $this->claimedUntil > $now;
    }
}
