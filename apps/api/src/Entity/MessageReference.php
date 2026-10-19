<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A Message-ID this message refers to (In-Reply-To and References headers). Indexed per account so a new message can
 * find its thread from either direction: a reply to an existing message, or an earlier message whose reply arrived
 * first.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['message_id', 'referenced_message_id'])]
#[ORM\Index(columns: ['mail_account_id', 'referenced_message_id'])]
class MessageReference
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MailAccount $mailAccount;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Message $message;

    #[ORM\Column(length: 998)]
    private string $referencedMessageId;

    public function __construct(Message $message, string $referencedMessageId)
    {
        $this->id = Uuid::v7();
        $this->mailAccount = $message->getMailAccount();
        $this->message = $message;
        $this->referencedMessageId = $referencedMessageId;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getMailAccount(): MailAccount
    {
        return $this->mailAccount;
    }

    public function getMessage(): Message
    {
        return $this->message;
    }

    public function getReferencedMessageId(): string
    {
        return $this->referencedMessageId;
    }
}
