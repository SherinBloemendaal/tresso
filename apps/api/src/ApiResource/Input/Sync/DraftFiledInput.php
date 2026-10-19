<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Sync;

use Symfony\Component\Validator\Constraints as Assert;

/** The worker appended the copy of `version` to the Drafts folder and removed the copy before it. */
final class DraftFiledInput extends WorkerRequest
{
    #[Assert\Positive]
    public int $version = 0;

    #[Assert\NotBlank]
    #[Assert\Length(max: 998)]
    public string $messageId = '';
}
