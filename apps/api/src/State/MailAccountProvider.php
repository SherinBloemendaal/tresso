<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MailAccountResource;
use App\Entity\MailAccount;
use App\Repository\MailAccountRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Only ever returns the signed-in user's own accounts.
 *
 * @implements ProviderInterface<MailAccountResource>
 */
final readonly class MailAccountProvider implements ProviderInterface
{
    public function __construct(
        private MailAccountRepository $accounts,
        private CurrentUser $currentUser,
    ) {
    }

    /**
     * @return MailAccountResource|list<MailAccountResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MailAccountResource|array
    {
        $owner = $this->currentUser->get();

        if ($operation instanceof CollectionOperationInterface) {
            return array_map(MailAccountResource::fromEntity(...), $this->accounts->findOwnedBy($owner));
        }

        $id = $uriVariables['id'] ?? null;
        $account = \is_string($id) ? $this->accounts->findOneOwnedBy($owner, $id) : null;
        if (!$account instanceof MailAccount) {
            throw new NotFoundHttpException('Mail account not found.');
        }

        return MailAccountResource::fromEntity($account);
    }
}
