<?php

declare(strict_types=1);

namespace App\Scan\Clamd;

use App\Enum\ScanFailure;
use App\Scan\ScannerUnavailable;
use App\Scan\ScanOutcome;
use App\Scan\VirusScanner;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Talks to clamd over TCP with the INSTREAM command, so content goes straight from object storage to the scanner
 * without touching the disk. An empty CLAMAV_HOST turns scanning off.
 *
 * @see https://docs.clamav.net/manual/Usage/Scanning.html#clamd
 */
#[AsAlias(VirusScanner::class)]
final readonly class ClamdScanner implements VirusScanner
{
    /**
     * Bytes per INSTREAM chunk, each sent behind its 4-byte length. clamd's StreamMaxLength caps the whole stream (see
     * CLAMAV_MAX_SIZE), not a chunk; small chunks keep memory flat on both sides.
     */
    private const int CHUNK_SIZE = 65536;

    public function __construct(
        #[Autowire(env: 'CLAMAV_HOST')]
        private string $host,
        #[Autowire(env: 'int:CLAMAV_PORT')]
        private int $port,
        #[Autowire(env: 'int:CLAMAV_MAX_SIZE')]
        private int $maxSize,
        #[Autowire(env: 'float:CLAMAV_CONNECT_TIMEOUT')]
        private float $connectTimeout,
        #[Autowire(env: 'float:CLAMAV_TIMEOUT')]
        private float $timeout,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== $this->host;
    }

    public function maxSize(): int
    {
        return $this->maxSize;
    }

    public function engine(): string
    {
        $socket = $this->connect();
        try {
            $this->write($socket, "zVERSION\0");

            return ClamdReply::version($this->read($socket));
        } finally {
            fclose($socket);
        }
    }

    public function scan(iterable $chunks): ScanOutcome
    {
        $socket = $this->connect();
        try {
            $this->write($socket, "zINSTREAM\0");
            $sent = 0;
            foreach ($chunks as $chunk) {
                for ($offset = 0, $length = \strlen($chunk); $offset < $length; $offset += self::CHUNK_SIZE) {
                    $piece = substr($chunk, $offset, self::CHUNK_SIZE);
                    $sent += \strlen($piece);
                    if ($sent > $this->maxSize) {
                        return ScanOutcome::failed(ScanFailure::TooLarge);
                    }
                    try {
                        $this->write($socket, pack('N', \strlen($piece)).$piece);
                    } catch (ScannerUnavailable $exception) {
                        // clamd hangs up once a stream passes its own StreamMaxLength, after saying so.
                        return ClamdReply::parse($this->readAfterHangUp($socket) ?? throw $exception);
                    }
                }
            }
            $this->write($socket, pack('N', 0));

            return ClamdReply::parse($this->read($socket));
        } finally {
            fclose($socket);
        }
    }

    /**
     * @return resource
     */
    private function connect()
    {
        if (!$this->isEnabled()) {
            throw new \LogicException('Scanning is turned off: CLAMAV_HOST is empty.');
        }

        $host = str_contains($this->host, ':') ? '['.$this->host.']' : $this->host;
        $socket = @stream_socket_client(\sprintf('tcp://%s:%d', $host, $this->port), $errorCode, $errorMessage, $this->connectTimeout);
        if (false === $socket) {
            throw new ScannerUnavailable(\sprintf('Cannot connect to clamd at %s:%d: %s (%d).', $this->host, $this->port, $errorMessage, $errorCode));
        }
        $seconds = (int) floor($this->timeout);
        stream_set_timeout($socket, $seconds, (int) (($this->timeout - $seconds) * 1_000_000));

        return $socket;
    }

    /**
     * @param resource $socket
     */
    private function write($socket, string $data): void
    {
        while ('' !== $data) {
            $written = @fwrite($socket, $data);
            if (false === $written || 0 === $written) {
                throw new ScannerUnavailable(stream_get_meta_data($socket)['timed_out'] ? 'clamd stopped reading (timeout).' : 'clamd closed the connection.');
            }
            $data = substr($data, $written);
        }
    }

    /**
     * Reads one null-terminated reply.
     *
     * @param resource $socket
     */
    private function read($socket): string
    {
        $reply = '';
        while (!str_contains($reply, "\0")) {
            $data = @fread($socket, 4096);
            if (false === $data || '' === $data) {
                if (stream_get_meta_data($socket)['timed_out']) {
                    throw new ScannerUnavailable('clamd did not answer in time.');
                }
                if (feof($socket)) {
                    break;
                }
                continue;
            }
            $reply .= $data;
        }
        if ('' === $reply) {
            throw new ScannerUnavailable('clamd closed the connection without answering.');
        }

        return $reply;
    }

    /**
     * @param resource $socket
     */
    private function readAfterHangUp($socket): ?string
    {
        try {
            return $this->read($socket);
        } catch (ScannerUnavailable) {
            return null;
        }
    }
}
