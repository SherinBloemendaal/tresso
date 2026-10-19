<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Outbox;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A message to send from one of the user's accounts. Without sendAt it goes out right away; a sendAt in the future
 * schedules it (send later, or a few seconds for an undo window) until then it can be cancelled.
 */
final class SendMessageInput
{
    private const int MAX_RECIPIENTS = 100;

    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $mailAccountId = '';

    /** @var list<RecipientInput> */
    #[Assert\Valid]
    public array $to = [];

    /** @var list<RecipientInput> */
    #[Assert\Valid]
    public array $cc = [];

    /** @var list<RecipientInput> */
    #[Assert\Valid]
    public array $bcc = [];

    #[Assert\Length(max: 998)]
    #[Assert\Regex(pattern: '/[\r\n]/', match: false, message: 'The subject cannot contain line breaks.')]
    public string $subject = '';

    #[Assert\Length(max: 2_000_000)]
    public string $html = '';

    /** The message this one answers or forwards: it keeps the conversation together for every recipient. */
    #[Assert\Uuid]
    public ?string $inReplyToMessageId = null;

    public ?\DateTimeImmutable $sendAt = null;

    #[Assert\Callback]
    public function validateRecipients(ExecutionContextInterface $context): void
    {
        $count = \count($this->to) + \count($this->cc) + \count($this->bcc);
        if (0 === $count) {
            $context->buildViolation('Add at least one recipient.')->atPath('to')->addViolation();
        }
        if ($count > self::MAX_RECIPIENTS) {
            $context->buildViolation(\sprintf('A message can have at most %d recipients.', self::MAX_RECIPIENTS))->atPath('to')->addViolation();
        }
    }

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
        if ($this->sendAt > $now->modify('+1 year')) {
            $context->buildViolation('Messages can be scheduled up to a year ahead.')->atPath('sendAt')->addViolation();
        }
    }
}
