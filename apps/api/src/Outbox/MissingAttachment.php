<?php

declare(strict_types=1);

namespace App\Outbox;

/** A file attached to the message is no longer in object storage, so the message cannot be built. */
final class MissingAttachment extends \RuntimeException
{
    public function __construct(public readonly string $filename, ?\Throwable $previous = null)
    {
        parent::__construct(\sprintf('The attachment "%s" is no longer available.', $filename), 0, $previous);
    }
}
