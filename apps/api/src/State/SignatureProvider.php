<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\SignatureResource;
use App\Entity\Signature;
use App\Repository\MailAccountRepository;
use App\Repository\SignatureRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Only ever returns the signed-in user's own signatures.
 *
 * @implements ProviderInterface<SignatureResource>
 */
final readonly class SignatureProvider implements ProviderInterface
{
    public function __construct(
        private SignatureRepository $signatures,
        private MailAccountRepository $accounts,
        private CurrentUser $currentUser,
    ) {
    }

    /**
     * @return SignatureResource|list<SignatureResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SignatureResource|array
    {
        $owner = $this->currentUser->get();
        $accounts = $this->accounts->findOwnedBy($owner);

        if ($operation instanceof CollectionOperationInterface) {
            return array_map(
                static fn (Signature $signature): SignatureResource => SignatureResource::fromEntity($signature, $accounts),
                $this->signatures->findOwnedBy($owner),
            );
        }

        $id = $uriVariables['id'] ?? null;
        $signature = \is_string($id) ? $this->signatures->findOneOwnedBy($owner, $id) : null;
        if (!$signature instanceof Signature) {
            throw new NotFoundHttpException('Signature not found.');
        }

        return SignatureResource::fromEntity($signature, $accounts);
    }
}
