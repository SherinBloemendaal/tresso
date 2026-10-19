<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ScanStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Attachment metadata. Content is stored once per SHA-256 in object storage.
 */
#[ORM\Entity]
#[ORM\Index(columns: ['message_id'])]
#[ORM\Index(columns: ['sha256'])]
class Attachment
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

    #[ORM\Column(length: 255)]
    private string $filename;

    #[ORM\Column(length: 255)]
    private string $contentType;

    #[ORM\Column(type: Types::BIGINT)]
    private int $size;

    #[ORM\Column(length: 64)]
    private string $sha256;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contentId = null;

    #[ORM\Column]
    private bool $inline = false;

    #[ORM\Column(length: 16, enumType: ScanStatus::class)]
    private ScanStatus $scanStatus = ScanStatus::Pending;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $scanSignature = null;

    public function __construct(Message $message, string $filename, string $contentType, int $size, string $sha256)
    {
        $this->id = Uuid::v7();
        $this->mailAccount = $message->getMailAccount();
        $this->message = $message;
        $this->filename = $filename;
        $this->contentType = $contentType;
        $this->size = $size;
        $this->sha256 = $sha256;
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

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getContentType(): string
    {
        return $this->contentType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getSha256(): string
    {
        return $this->sha256;
    }

    /** Object storage key; content-addressed so identical files are stored once. */
    public function getObjectKey(): string
    {
        return 'attachments/'.substr($this->sha256, 0, 2).'/'.$this->sha256;
    }

    public function getContentId(): ?string
    {
        return $this->contentId;
    }

    public function isInline(): bool
    {
        return $this->inline;
    }

    public function markInline(string $contentId): void
    {
        $this->inline = true;
        $this->contentId = $contentId;
    }

    public function getScanStatus(): ScanStatus
    {
        return $this->scanStatus;
    }

    public function getScanSignature(): ?string
    {
        return $this->scanSignature;
    }

    public function recordScan(ScanStatus $status, ?string $signature = null): void
    {
        $this->scanStatus = $status;
        $this->scanSignature = $signature;
    }
}
