<?php

declare(strict_types=1);

namespace App\Classification;

/**
 * Whether the receiving server vouches for the From domain: DMARC passed according to an Authentication-Results
 * header that server surely wrote (see {@see ReceivingServers}). Then the From domain sent the message, so its
 * headers (List-Unsubscribe among them) are the sender's own and rules about that sender can be trusted.
 */
final class SenderAuthentication
{
    public static function dmarcPassed(?string $authenticationResults, bool $trusted): bool
    {
        return $trusted && 1 === preg_match('/\bdmarc=pass\b/', strtolower($authenticationResults ?? ''));
    }
}
