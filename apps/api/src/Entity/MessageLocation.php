<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Where a message lives on the server: one row per folder (or Gmail label) the message is in.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['folder_id', 'remote_uid'])]
#[ORM\Index(columns: ['message_id'])]
class MessageLocation
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

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Folder $folder;

    /** IMAP UID, or the provider's message id for Gmail and Microsoft Graph. */
    #[ORM\Column(length: 255)]
    private string $remoteUid;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $modSeq = null;

    public function __construct(Message $message, Folder $folder, string $remoteUid)
    {
        $this->id = Uuid::v7();
        $this->mailAccount = $message->getMailAccount();
        $this->message = $message;
        $this->folder = $folder;
        $this->remoteUid = $remoteUid;
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

    public function getFolder(): Folder
    {
        return $this->folder;
    }

    public function getRemoteUid(): string
    {
        return $this->remoteUid;
    }

    public function getModSeq(): ?int
    {
        return $this->modSeq;
    }

    public function setModSeq(?int $modSeq): void
    {
        $this->modSeq = $modSeq;
    }
}
