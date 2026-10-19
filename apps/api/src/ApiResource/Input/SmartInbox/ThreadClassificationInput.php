<?php

declare(strict_types=1);

namespace App\ApiResource\Input\SmartInbox;

use App\Enum\Verdict;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Where the user wants a conversation: a smart inbox, and a verdict when it should differ from the smart inbox's.
 */
final class ThreadClassificationInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $smartInboxId = '';

    /** null: the smart inbox's verdict. */
    #[Assert\Choice(callback: [Verdict::class, 'values'])]
    public ?string $verdict = null;
}
