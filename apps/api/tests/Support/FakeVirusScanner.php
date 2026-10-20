<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Scan\ScanError;
use App\Scan\ScannerUnavailable;
use App\Scan\ScanOutcome;
use App\Scan\VirusScanner;

/**
 * Finds the EICAR test file like clamd does (content that starts with the EICAR string), and can be switched off or
 * made unreachable.
 */
final class FakeVirusScanner implements VirusScanner
{
    public const string EICAR = 'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
    public const string SIGNATURE = 'Win.Test.EICAR_HDB-1';

    public bool $enabled = true;

    public bool $unavailable = false;

    /** Answers scans with an error, like clamd out of memory. */
    public bool $failing = false;

    public int $maxSize = 1024;

    public string $engine = 'ClamAV 1.5.1/27800';

    /** Content that counts as clean even though it is the EICAR file, to stand in for a fixed false positive. */
    public ?string $cleared = null;

    /** @var list<string> content that counts as malware too, as if new signatures matched it */
    public array $alsoInfected = [];

    /** @var list<string> every content scanned, in order */
    public array $scanned = [];

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function maxSize(): int
    {
        return $this->maxSize;
    }

    public function engine(): string
    {
        if ($this->unavailable) {
            throw new ScannerUnavailable('Cannot connect to clamd.');
        }

        return $this->engine;
    }

    public function scan(iterable $chunks): ScanOutcome
    {
        if ($this->unavailable) {
            throw new ScannerUnavailable('Cannot connect to clamd.');
        }
        if ($this->failing) {
            throw new ScanError('clamd could not scan: "stream: Can\'t allocate memory ERROR".');
        }
        $content = implode('', [...$chunks]);
        $this->scanned[] = $content;

        $infected = (str_starts_with($content, self::EICAR) && $content !== $this->cleared) || \in_array($content, $this->alsoInfected, true);

        return $infected ? ScanOutcome::infected(self::SIGNATURE) : ScanOutcome::clean();
    }
}
