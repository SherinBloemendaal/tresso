<?php

declare(strict_types=1);

namespace App\ApiResource\Input\Bulk;

use App\Enum\MessageAction;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Marks read, archives, trashes or moves every conversation of the scope. A move goes to a folder of one account, so
 * it needs `mailAccountId` set to that folder's account.
 */
final class BulkActionInput extends BulkScopeInput
{
    #[Assert\NotNull]
    #[Assert\Choice(choices: [MessageAction::MarkRead, MessageAction::Archive, MessageAction::Trash, MessageAction::Move], message: 'Choose mark read, archive, trash or move.')]
    public ?MessageAction $action = null;

    /** The folder to move to; only for `move`. */
    #[Assert\Uuid]
    public ?string $folderId = null;

    #[Assert\Callback]
    public function validateFolder(ExecutionContextInterface $context): void
    {
        if (MessageAction::Move === $this->action && null === $this->folderId) {
            $context->buildViolation('Choose the folder to move to.')->atPath('folderId')->addViolation();
        }
        if (MessageAction::Move === $this->action && null === $this->mailAccountId) {
            $context->buildViolation('A move stays within one account: choose it.')->atPath('mailAccountId')->addViolation();
        }
        if (null !== $this->action && MessageAction::Move !== $this->action && null !== $this->folderId) {
            $context->buildViolation('Only a move takes a folder.')->atPath('folderId')->addViolation();
        }
    }
}
