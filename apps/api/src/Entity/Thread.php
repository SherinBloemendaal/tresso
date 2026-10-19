<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Index(columns: ['mail_account_id', 'last_message_at'])]
class Thread
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MailAccount $mailAccount;

    #[ORM\Column(length: 998)]
    private string $subject;

    #[ORM\Column]
    private \DateTimeImmutable $lastMessageAt;

    #[ORM\Column]
    private int $messageCount = 0;

    public function __construct(MailAccount $mailAccount, string $subject, \DateTimeImmutable $lastMessageAt)
    {
        $this->id = Uuid::v7();
        $this->mailAccount = $mailAccount;
        $this->subject = $subject;
        $this->lastMessageAt = $lastMessageAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMailAccount(): MailAccount
    {
        return $this->mailAccount;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getLastMessageAt(): \DateTimeImmutable
    {
        return $this->lastMessageAt;
    }

    public function getMessageCount(): int
    {
        return $this->messageCount;
    }

    public function recordMessage(\DateTimeImmutable $receivedAt): void
    {
        ++$this->messageCount;
        if ($receivedAt > $this->lastMessageAt) {
            $this->lastMessageAt = $receivedAt;
        }
    }
}
