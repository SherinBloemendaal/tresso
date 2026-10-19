<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * The server must be reachable over the public internet and use an encrypted connection, so users cannot point the
 * sync workers at internal services. The sync workers repeat the address check when they connect.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class SafeMailServer extends Constraint
{
    public string $privateAddressMessage = 'This mail server resolves to a private or reserved address.';
    public string $unresolvableMessage = 'This mail server could not be found.';
    public string $insecureMessage = 'Unencrypted connections are not allowed. Use TLS or STARTTLS.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
