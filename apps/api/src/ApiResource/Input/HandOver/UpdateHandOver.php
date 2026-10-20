<?php

declare(strict_types=1);

namespace App\ApiResource\Input\HandOver;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Partial update (JSON merge patch). Omitted or null fields stay as they are.
 */
final class UpdateHandOver
{
    /** A new due date; the user is told again once it passes without a reply. */
    public ?\DateTimeImmutable $dueAt = null;

    /** True marks it done; false puts a closed hand-over back in "Waiting on". */
    public ?bool $done = null;

    #[Assert\Callback]
    public function validateDueAt(ExecutionContextInterface $context): void
    {
        DueDate::validate($this->dueAt, $context);
    }
}
