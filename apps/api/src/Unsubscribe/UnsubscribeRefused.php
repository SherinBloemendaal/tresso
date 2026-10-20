<?php

declare(strict_types=1);

namespace App\Unsubscribe;

/** The message cannot be unsubscribed from; the message says why, for the user. */
final class UnsubscribeRefused extends \RuntimeException
{
}
