<?php

declare(strict_types=1);

namespace App\Scan\Clamd;

use App\Enum\ScanFailure;
use App\Scan\ScanError;
use App\Scan\ScannerUnavailable;
use App\Scan\ScanOutcome;

/**
 * Reads clamd's answers: to INSTREAM "stream: OK", "stream: <signature> FOUND" or "<reason> ERROR", and to VERSION
 * "ClamAV <engine>/<signature database version>/<database date>".
 */
final class ClamdReply
{
    private const string PREFIX = 'stream: ';

    /**
     * The engine and signature database version. A clamd that reports no signature database would call everything
     * clean, so it counts as unavailable.
     *
     * @throws ScannerUnavailable
     */
    public static function version(string $reply): string
    {
        $version = rtrim($reply, "\0\r\n ");
        if (1 !== preg_match('~^ClamAV [^/\s]+/\d+/~', $version)) {
            throw new ScannerUnavailable(\sprintf('clamd reports no signature database: "%s".', mb_strimwidth($version, 0, 200)));
        }

        return $version;
    }

    /**
     * Heuristic alerts that say the content could not be checked (encrypted, or past clamd's scan limits) rather than
     * that it is malware. The shipped clamd.conf turns them on, so such files are never called clean.
     */
    private const array UNCHECKED = ['Heuristics.Encrypted.', 'Heuristics.Limits.Exceeded'];

    /**
     * @throws ScanError          for an error reply other than the size limit, which may well pass on a retry
     * @throws ScannerUnavailable for anything that is not an answer to INSTREAM
     */
    public static function parse(string $reply): ScanOutcome
    {
        $reply = rtrim($reply, "\0\r\n ");

        if (self::PREFIX.'OK' === $reply) {
            return ScanOutcome::clean();
        }
        if (str_starts_with($reply, self::PREFIX) && str_ends_with($reply, ' FOUND')) {
            $signature = trim(substr($reply, \strlen(self::PREFIX), -\strlen(' FOUND')));
            foreach (self::UNCHECKED as $prefix) {
                if (str_starts_with($signature, $prefix)) {
                    return ScanOutcome::failed(ScanFailure::Unscannable);
                }
            }
            if ('' !== $signature) {
                return ScanOutcome::infected($signature);
            }
        }
        if (str_ends_with($reply, ' ERROR')) {
            if (str_contains($reply, 'size limit exceeded')) {
                return ScanOutcome::failed(ScanFailure::TooLarge);
            }
            throw new ScanError(\sprintf('clamd could not scan: "%s".', mb_strimwidth($reply, 0, 200)));
        }

        throw new ScannerUnavailable(\sprintf('Unexpected reply from clamd: "%s".', mb_strimwidth($reply, 0, 200)));
    }
}
