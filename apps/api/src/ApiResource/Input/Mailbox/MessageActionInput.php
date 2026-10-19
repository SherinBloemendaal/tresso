<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Mailbox;

use App\Enum\MessageAction;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * One action on up to 500 of the user's messages, e.g. archive a whole conversation or mark a selection read.
 */
final class MessageActionInput
{
    public const int MAX_MESSAGES = 500;

    /** @var list<string> */
    #[Assert\Count(min: 1, max: self::MAX_MESSAGES)]
    #[Assert\All(constraints: [new Assert\Uuid()])]
    public array $messageIds = [];

    #[Assert\NotNull]
    public ?MessageAction $action = null;

    /** The folder to move into; only for `move`, and of the same account as the messages. */
    #[Assert\Uuid]
    public ?string $folderId = null;

    /**
     * For `move`: only the copies in this folder move (an undo puts back exactly what moved); without it every copy
     * outside the target does.
     */
    #[Assert\Uuid]
    public ?string $fromFolderId = null;

    #[Assert\Callback]
    public function validateFolder(ExecutionContextInterface $context): void
    {
        if (MessageAction::Move !== $this->action && null !== $this->fromFolderId) {
            $context->buildViolation('Only a move takes a folder to move from.')->atPath('fromFolderId')->addViolation();
        }
        if (MessageAction::Move === $this->action && null === $this->folderId) {
            $context->buildViolation('Choose the folder to move to.')->atPath('folderId')->addViolation();
        }
        if (null !== $this->action && MessageAction::Move !== $this->action && null !== $this->folderId) {
            $context->buildViolation('Only a move takes a folder.')->atPath('folderId')->addViolation();
        }
    }
}
