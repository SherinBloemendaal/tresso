<?php

declare(strict_types=1);

namespace App\Storage;

final class ObjectNotFound extends \RuntimeException
{
    public static function forKey(string $key, ?\Throwable $previous = null): self
    {
        return new self(\sprintf('Object "%s" does not exist.', $key), 0, $previous);
    }
}
