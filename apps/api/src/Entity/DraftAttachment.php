<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ScanStatus;
use App\Repository\DraftAttachmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A file attached to a draft: uploaded by the user, or taken from the message a forward is written about. The content
 * lives in object storage under its SHA-256, so the same file attached twice is stored once; it goes out with the
 * message and into the copy in the Drafts folder.
 */
#[ORM\Entity(repositoryClass: DraftAttachmentRepository::class)]
#[ORM\Index(columns: ['object_key'])]
class DraftAttachment
{
    /** Uploads live under this prefix; files taken from received mail keep their own key. */
    public const string UPLOAD_PREFIX = 'uploads/';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Draft $draft;

    #[ORM\Column(length: 255)]
    private string $filename;

    #[ORM\Column(length: 255)]
    private string $contentType;

    #[ORM\Column(type: Types::BIGINT)]
    private int $size;

    #[ORM\Column(length: 64)]
    private string $sha256;

    #[ORM\Column(length: 255)]
    private string $objectKey;

    #[ORM\Column(length: 16, enumType: ScanStatus::class, options: ['default' => 'pending'])]
    private ScanStatus $scanStatus = ScanStatus::Pending;

    /** What the scanner found, for an infected file. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $scanSignature = null;

    /**
     * Shown inside the text (an image of the original a reply or forward quotes), referred to as cid:{contentId},
     * rather than listed as a file.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $inline = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contentId = null;

    /** Order in the draft: attachments keep the order they were added in. */
    #[ORM\Column]
    private int $position;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Draft $draft, string $filename, string $contentType, int $size, string $sha256, string $objectKey, int $position, \DateTimeImmutable $now)
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            throw new \InvalidArgumentException('An attachment is stored under its SHA-256 in hex.');
        }
        $this->id = Uuid::v7();
        $this->draft = $draft;
        $this->filename = $filename;
        $this->contentType = $contentType;
        $this->size = $size;
        $this->sha256 = $sha256;
        $this->objectKey = $objectKey;
        $this->position = $position;
        $this->createdAt = $now;
    }

    /** Where an uploaded file with this content is stored. */
    public static function uploadKey(string $sha256): string
    {
        return self::UPLOAD_PREFIX.substr($sha256, 0, 2).'/'.$sha256;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getDraft(): Draft
    {
        return $this->draft;
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

    public function getObjectKey(): string
    {
        return $this->objectKey;
    }

    public function isUpload(): bool
    {
        return str_starts_with($this->objectKey, self::UPLOAD_PREFIX);
    }

    public function getScanStatus(): ScanStatus
    {
        return $this->scanStatus;
    }

    public function getScanSignature(): ?string
    {
        return $this->scanSignature;
    }

    /** Takes the scanner's verdict (the same as for a received attachment with this content). */
    public function recordScan(ScanStatus $status, ?string $signature): void
    {
        $this->scanStatus = $status;
        $this->scanSignature = ScanStatus::Infected === $status ? $signature : null;
    }

    /** Taken from a received attachment, whose scan applies to this copy too, and which stays inline if it was. */
    public function takeFrom(Attachment $attachment): void
    {
        $this->recordScan($attachment->getScanStatus(), $attachment->getScanSignature());
        $contentId = $attachment->getContentId();
        if ($attachment->isInline() && null !== $contentId && '' !== $contentId) {
            $this->inline = true;
            $this->contentId = $contentId;
        }
    }

    public function isInline(): bool
    {
        return $this->inline;
    }

    public function getContentId(): ?string
    {
        return $this->contentId;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
