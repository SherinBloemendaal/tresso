<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ScanStatus;
use App\Repository\ScanResultRepository;
use App\Scan\ScanCacheKey;
use App\Scan\ScanOutcome;
use Doctrine\ORM\Mapping as ORM;

/**
 * The last definitive verdict (clean or infected) for a piece of attachment content, shared by all users: the same
 * file mailed to many people, or synced from several folders, is scanned once. Rescans overwrite it.
 *
 * Keyed by HMAC-SHA256(scan cache key, SHA-256 of the content) ({@see \App\Scan\ScanCacheKey}), with the id of the
 * master key the cache key comes from. Nothing ties a row to a user or an attachment, and without the master key a
 * database dump cannot test whether a known file was ever received. Rows of another master key, and rows from before
 * ($keyId null, keyed by the plain SHA-256), are never matched; key rotation and re-encryption delete them.
 */
#[ORM\Entity(repositoryClass: ScanResultRepository::class)]
class ScanResult
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    private string $contentKey;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $keyId;

    #[ORM\Column(length: 16, enumType: ScanStatus::class)]
    private ScanStatus $status;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $signature;

    /** The clamd engine and signature version that gave the verdict. */
    #[ORM\Column(length: 255)]
    private string $engine;

    #[ORM\Column]
    private \DateTimeImmutable $scannedAt;

    public function __construct(ScanCacheKey $key, ScanOutcome $outcome, string $engine, \DateTimeImmutable $scannedAt)
    {
        $this->contentKey = $key->contentKey;
        $this->keyId = $key->keyId;
        $this->record($outcome, $engine, $scannedAt);
    }

    public function getContentKey(): string
    {
        return $this->contentKey;
    }

    public function getKeyId(): ?string
    {
        return $this->keyId;
    }

    public function getStatus(): ScanStatus
    {
        return $this->status;
    }

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    public function getEngine(): string
    {
        return $this->engine;
    }

    public function getScannedAt(): \DateTimeImmutable
    {
        return $this->scannedAt;
    }

    public function outcome(): ScanOutcome
    {
        if (ScanStatus::Clean === $this->status) {
            return ScanOutcome::clean();
        }

        return ScanOutcome::infected($this->signature ?? throw new \LogicException('An infected verdict always has a signature.'));
    }

    public function record(ScanOutcome $outcome, string $engine, \DateTimeImmutable $scannedAt): void
    {
        if (!$outcome->isDefinitive()) {
            throw new \InvalidArgumentException('Only clean and infected verdicts are cached.');
        }
        $this->status = $outcome->status;
        $this->signature = $outcome->signature;
        $this->engine = mb_substr($engine, 0, 255);
        $this->scannedAt = $scannedAt;
    }
}
