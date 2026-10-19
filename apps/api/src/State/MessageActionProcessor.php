<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\Mailbox\MessageActionInput;
use App\ApiResource\MessageActionResource;
use App\Entity\Folder;
use App\Enum\FolderRole;
use App\Enum\MessageAction;
use App\Mailbox\MessageActions;
use App\Mailbox\MissingFolder;
use App\Repository\MessageRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @implements ProcessorInterface<MessageActionInput, MessageActionResource>
 */
final readonly class MessageActionProcessor implements ProcessorInterface
{
    public function __construct(
        private MessageRepository $messages,
        private MessageActions $actions,
        private CurrentUser $currentUser,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MessageActionResource
    {
        $action = $data->action ?? throw new \LogicException('Validation guarantees an action.');
        $owner = $this->currentUser->get();

        // One transaction, so change numbers for client sync commit in order.
        return $this->entityManager->wrapInTransaction(function () use ($data, $action, $owner): MessageActionResource {
            $ids = array_values(array_unique(array_map(strtolower(...), $data->messageIds)));
            $messages = $this->messages->lockOwnedBy($owner, $ids);

            $violations = new ConstraintViolationList();
            foreach ($data->messageIds as $index => $id) {
                if (!isset($messages[strtolower($id)])) {
                    $violations->add(new ConstraintViolation('This message does not exist.', null, [], $data, \sprintf('messageIds[%d]', $index), $id));
                }
            }

            $target = null;
            if (MessageAction::Move === $action) {
                $target = $this->ownedFolder($data->folderId ?? '', $owner->getId());
                if (null === $target) {
                    $violations->add(new ConstraintViolation('This folder does not exist.', null, [], $data, 'folderId', $data->folderId));
                } else {
                    foreach ($messages as $message) {
                        if ($message->getMailAccount() !== $target->getMailAccount()) {
                            $violations->add(new ConstraintViolation('Messages can only move to a folder of their own account.', null, [], $data, 'folderId', $data->folderId));
                            break;
                        }
                    }
                    if (\in_array($target->getRole(), [FolderRole::Drafts, FolderRole::All], true)) {
                        $violations->add(new ConstraintViolation('Messages cannot be moved into this folder.', null, [], $data, 'folderId', $data->folderId));
                    }
                }
            }
            if (\count($violations) > 0) {
                throw new ValidationException($violations);
            }

            try {
                $changed = $this->actions->apply(array_values($messages), $action, $target, new \DateTimeImmutable());
            } catch (MissingFolder $missing) {
                throw new ValidationException(new ConstraintViolationList([new ConstraintViolation($missing->getMessage(), null, [], $data, 'action', $action->value)]));
            }

            return new MessageActionResource($changed);
        });
    }

    private function ownedFolder(string $id, Uuid $ownerId): ?Folder
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $folder = $this->entityManager->find(Folder::class, Uuid::fromString($id));

        return null !== $folder && $folder->getMailAccount()->getOwner()->getId()->equals($ownerId) ? $folder : null;
    }
}
