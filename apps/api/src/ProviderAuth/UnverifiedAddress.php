<?php

declare(strict_types=1);

namespace App\ProviderAuth;

/**
 * The provider account's address is not verified, so it cannot be trusted as the mailbox address.
 */
final class UnverifiedAddress extends \RuntimeException
{
    public function __construct(public readonly string $emailAddress)
    {
        parent::__construct('The provider has not verified this address.');
    }
}
