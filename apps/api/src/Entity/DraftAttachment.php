<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ScanFailure;
use App\Enum\ScanStatus;
use App\Repository\DraftAttachmentRepository;
use App\Scan\ScanOutcome;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A file attached to a draft: uploaded by the user, or taken from the message a forward is written about. The content
 * lives in object storage once per owner and content, encrypted with the owner's key, so the same file attached twice
 * is stored once; it goes out with the message and into the copy in the Drafts folder.
 *
 * $sha256 identifies the content within its owner: the owner's fingerprint, or for content stored before encryption
 * ($keyVersion null) the plain SHA-256 (see {@see Attachment}).
 */
#[ORM\Entity(repositoryClass: DraftAttachmentRepository::class)]
#[ORM\Index(columns: ['object_key'])]
#[ORM\Index(columns: ['sha256'])]
#[ORM\Index(columns: ['scan_status', 'scan_requested_at'])]
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

    /** The key version the content is encrypted with; null for plaintext stored before encryption. */
    #[ORM\Column(nullable: true)]
    private ?int $keyVersion;

    #[ORM\Column(length: 16, enumType: ScanStatus::class, options: ['default' => 'pending'])]
    private ScanStatus $scanStatus = ScanStatus::Pending;

    /** What the scanner found, for an infected file. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $scanSignature = null;

    #[ORM\Column(length: 16, nullable: true, enumType: ScanFailure::class)]
    private ?ScanFailure $scanFailure = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $scannedAt = null;

    /** When a scan was last queued; the sweeper queues one again for content still pending long after. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $scanRequestedAt = null;

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

    /**
     * @param ?int $keyVersion null only for a forwarded file stored before encryption
     */
    public function __construct(Draft $draft, string $filename, string $contentType, int $size, string $sha256, string $objectKey, ?int $keyVersion, int $position, \DateTimeImmutable $now)
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $sha256)) {
            throw new \InvalidArgumentException('An attachment is stored under its content fingerprint in hex.');
        }
        $this->id = Uuid::v7();
        $this->draft = $draft;
        $this->filename = $filename;
        $this->contentType = $contentType;
        $this->size = $size;
        $this->sha256 = $sha256;
        $this->objectKey = $objectKey;
        $this->keyVersion = $keyVersion;
        $this->position = $position;
        $this->createdAt = $now;
        $this->scanRequestedAt = $now;
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

    public function getKeyVersion(): ?int
    {
        return $this->keyVersion;
    }

    /** Whose key the content is encrypted with; null for content stored before encryption. */
    public function getContentOwner(): ?Uuid
    {
        return null === $this->keyVersion ? null : $this->draft->getOwner()->getId();
    }

    /** Where infected content is moved to; shared with received attachments of the same content. */
    public function getQuarantineKey(): string
    {
        return Attachment::quarantineKey($this->sha256, $this->getContentOwner());
    }

    /** The content was encrypted again (or for the first time) and is now stored at $objectKey. */
    public function reencrypted(string $objectKey, string $fingerprint, int $keyVersion): void
    {
        $this->objectKey = $objectKey;
        $this->sha256 = $fingerprint;
        $this->keyVersion = $keyVersion;
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

    public function getScanFailure(): ?ScanFailure
    {
        return $this->scanFailure;
    }

    public function getScannedAt(): ?\DateTimeImmutable
    {
        return $this->scannedAt;
    }

    /** Takes the scanner's verdict (the same as for a received attachment with this content). */
    public function recordScan(ScanOutcome $outcome, \DateTimeImmutable $scannedAt): void
    {
        $this->scanStatus = $outcome->status;
        $this->scanSignature = $outcome->signature;
        $this->scanFailure = $outcome->failure;
        $this->scannedAt = $scannedAt;
    }

    /**
     * Taken from a received attachment, whose scan applies to this copy too (also a verdict that arrives later), and
     * which stays inline if it was.
     */
    public function takeFrom(Attachment $attachment): void
    {
        $this->scanStatus = $attachment->getScanStatus();
        $this->scanSignature = $attachment->getScanSignature();
        $this->scanFailure = $attachment->getScanFailure();
        $this->scannedAt = $attachment->getScannedAt();
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
