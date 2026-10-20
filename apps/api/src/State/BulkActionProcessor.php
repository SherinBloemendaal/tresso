<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\BulkActionResource;
use App\ApiResource\Input\Bulk\BulkActionInput;
use App\Bulk\BulkActions;
use App\Bulk\BulkScopeResolver;
use App\Entity\Folder;
use App\Entity\User;
use App\Enum\FolderRole;
use App\Enum\MessageAction;
use App\Mailbox\MissingFolder;
use App\Repository\BulkActionRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @implements ProcessorInterface<BulkActionInput|null, BulkActionResource>
 */
final readonly class BulkActionProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BulkActions $bulkActions,
        private BulkActionRepository $repository,
        private BulkScopeResolver $scopes,
        private CurrentUser $currentUser,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BulkActionResource
    {
        $owner = $this->currentUser->get();

        if ($data instanceof BulkActionInput) {
            return $this->entityManager->wrapInTransaction(fn (): BulkActionResource => $this->start($owner, $data));
        }

        return $this->entityManager->wrapInTransaction(function () use ($owner, $uriVariables): BulkActionResource {
            $id = $uriVariables['id'] ?? null;
            $bulkAction = \is_string($id) ? $this->repository->findOneOwnedBy($owner, $id, lock: true) : null;
            if (null === $bulkAction) {
                throw new NotFoundHttpException('Bulk action not found.');
            }
            if (!$bulkAction->canUndo()) {
                throw new ConflictHttpException('This action cannot be undone (anymore).');
            }
            $this->bulkActions->undo($bulkAction);

            return BulkActionResource::fromEntity($bulkAction);
        });
    }

    private function start(User $owner, BulkActionInput $data): BulkActionResource
    {
        $action = $data->action ?? throw new \LogicException('Validation guarantees an action.');
        $now = new \DateTimeImmutable();
        $scope = $this->scopes->resolve($owner, $data, $now);

        $target = null;
        if (MessageAction::Move === $action) {
            $target = $this->ownedFolder($data->folderId ?? '', $owner);
            $violations = new ConstraintViolationList();
            if (null === $target) {
                $violations->add(new ConstraintViolation('This folder does not exist.', null, [], $data, 'folderId', $data->folderId));
            } elseif ($target->getMailAccount() !== $scope->mailAccount) {
                $violations->add(new ConstraintViolation('Messages can only move to a folder of their own account.', null, [], $data, 'folderId', $data->folderId));
            } elseif (\in_array($target->getRole(), [FolderRole::Drafts, FolderRole::All], true)) {
                $violations->add(new ConstraintViolation('Messages cannot be moved into this folder.', null, [], $data, 'folderId', $data->folderId));
            }
            if (\count($violations) > 0) {
                throw new ValidationException($violations);
            }
        }

        try {
            $bulkAction = $this->bulkActions->start($owner, $scope, $action, $target, $now);
        } catch (MissingFolder $missing) {
            throw new ValidationException(new ConstraintViolationList([new ConstraintViolation($missing->getMessage(), null, [], $data, 'action', $action->value)]));
        }

        return BulkActionResource::fromEntity($bulkAction);
    }

    private function ownedFolder(string $id, User $owner): ?Folder
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $folder = $this->entityManager->find(Folder::class, Uuid::fromString($id));

        return null !== $folder && $folder->getMailAccount()->getOwner()->getId()->equals($owner->getId()) ? $folder : null;
    }
}
