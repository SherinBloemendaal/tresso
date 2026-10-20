<?php

declare(strict_types=1);

namespace App\ApiResource\Input;

use Symfony\Component\Validator\Constraints as Assert;

/** The user's confirmation that they want a file that could not be scanned for malware. */
final class ConfirmUnscannedInput
{
    #[Assert\IsTrue(message: 'Confirm that you accept a file that could not be scanned for malware.')]
    public bool $confirmed = false;
}
