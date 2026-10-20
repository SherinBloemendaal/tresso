<?php

declare(strict_types=1);

namespace App\HandOver;

use App\Enum\FolderRole;

/**
 * Whether a stored message from the delegate is their answer, as far as its signals tell. An out-of-office or other
 * automatic reply is not (RFC 3834 Auto-Submitted, Precedence auto_reply, bulk, junk or list, and the X-Autoreply and
 * X-Autorespond markers some servers use). Neither is mail in Spam or flagged as junk, nor mail whose sender failed
 * DMARC: the From address is easy to forge. A DMARC failure counts in any Authentication-Results header, trusted or
 * not, like in classification: a forged failure can only keep a hand-over open, which the user can still close.
 */
final class ReplyCheck
{
    /**
     * @param array<string, string> $headers the signal headers the worker sent, by lower-cased name
     */
    public static function isAnswer(array $headers, bool $junk, ?FolderRole $folderRole): bool
    {
        if ($junk || FolderRole::Spam === $folderRole) {
            return false;
        }
        $autoSubmitted = strtolower(trim($headers['auto-submitted'] ?? ''));
        if ('' !== $autoSubmitted && 'no' !== $autoSubmitted) {
            return false;
        }
        if (\in_array(strtolower(trim($headers['precedence'] ?? '')), ['auto_reply', 'bulk', 'junk', 'list'], true)) {
            return false;
        }
        if (isset($headers['x-autoreply']) || isset($headers['x-autorespond'])) {
            return false;
        }

        return 1 !== preg_match('/\bdmarc=fail\b/', strtolower($headers['authentication-results'] ?? ''));
    }
}
