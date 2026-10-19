<?php

declare(strict_types=1);

namespace App\ProviderAuth;

final class ConnectFailed extends \RuntimeException
{
    public function __construct(public readonly ConnectError $error, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct('' === $message ? $error->value : $message, previous: $previous);
    }
}
