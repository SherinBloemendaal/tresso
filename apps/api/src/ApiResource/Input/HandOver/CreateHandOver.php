<?php

declare(strict_types=1);

namespace App\ApiResource\Input\HandOver;

use App\ApiResource\Input\Outbox\RecipientInput;
use App\Entity\HandOver;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Records a hand-over once its forward was sent: the message handed over, who it went to, the note that went along
 * and when an answer is due.
 */
final class CreateHandOver
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $messageId = '';

    #[Assert\NotNull]
    #[Assert\Valid]
    public ?RecipientInput $delegate = null;

    #[Assert\Length(max: HandOver::MAX_NOTE)]
    public string $note = '';

    #[Assert\NotNull]
    public ?\DateTimeImmutable $dueAt = null;

    /** The outgoing message that forwarded it to the delegate. */
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $forwardId = '';

    #[Assert\Callback]
    public function validateDueAt(ExecutionContextInterface $context): void
    {
        DueDate::validate($this->dueAt, $context);
    }
}
