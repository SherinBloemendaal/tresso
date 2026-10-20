<?php

declare(strict_types=1);

namespace App\Classification;

use App\Entity\MailAccount;
use App\Enum\MailProvider;

/**
 * Whether to believe a message's Authentication-Results header. Anyone can put such a header in a message; only the
 * one the receiving server added counts. Gmail and Microsoft 365 always add theirs at the top, so the topmost header
 * (the one the worker sends) is theirs. A generic IMAP server may add none, and then the topmost header is whatever
 * the sender wrote, naming any server it likes, including the recipient's own. So for those accounts a DMARC pass
 * proves nothing and never overrules the server's spam verdict.
 */
final class ReceivingServers
{
    public static function trustsTopmostHeader(MailAccount $account): bool
    {
        return self::trustsTopmostHeaderAt($account->getProvider());
    }

    public static function trustsTopmostHeaderAt(MailProvider $provider): bool
    {
        return MailProvider::Imap !== $provider;
    }
}
