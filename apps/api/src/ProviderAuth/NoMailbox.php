<?php

declare(strict_types=1);

namespace App\ProviderAuth;

/**
 * The signed-in provider account has no mailbox address (for example a Microsoft account without Exchange Online).
 */
final class NoMailbox extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The provider account has no mailbox.');
    }
}
