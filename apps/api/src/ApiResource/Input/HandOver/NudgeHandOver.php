<?php

declare(strict_types=1);

namespace App\ApiResource\Input\HandOver;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A follow-up to the delegate, sent as a reply in the same conversation. A sendAt a few seconds ahead leaves room to
 * undo it (cancel the outgoing message).
 */
final class NudgeHandOver
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 2_000_000)]
    public string $html = '';

    public ?\DateTimeImmutable $sendAt = null;

    #[Assert\Callback]
    public function validateSendAt(ExecutionContextInterface $context): void
    {
        if (null === $this->sendAt) {
            return;
        }
        $now = new \DateTimeImmutable();
        if ($this->sendAt < $now->modify('-1 minute')) {
            $context->buildViolation('The send time is in the past.')->atPath('sendAt')->addViolation();
        }
        if ($this->sendAt > $now->modify('+1 day')) {
            $context->buildViolation('A nudge can wait at most a day.')->atPath('sendAt')->addViolation();
        }
    }
}
