<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Unsubscribe;

use Symfony\Component\Validator\Constraints as Assert;

final class UnsubscribeInput
{
    /** The message to unsubscribe from: its account stops getting mail from its sender. */
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $messageId = '';
}
