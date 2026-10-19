<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\SenderRuleResource;
use App\Entity\SenderRule;
use App\Repository\SenderRuleRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Only ever returns the signed-in user's own sender rules, sorted by sender.
 *
 * @implements ProviderInterface<SenderRuleResource>
 */
final readonly class SenderRuleProvider implements ProviderInterface
{
    public function __construct(
        private SenderRuleRepository $rules,
        private CurrentUser $currentUser,
    ) {
    }

    /**
     * @return SenderRuleResource|list<SenderRuleResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SenderRuleResource|array
    {
        $owner = $this->currentUser->get();

        if ($operation instanceof CollectionOperationInterface) {
            return array_map(SenderRuleResource::fromEntity(...), $this->rules->findOwnedBy($owner));
        }

        $id = $uriVariables['id'] ?? null;
        $rule = \is_string($id) ? $this->rules->findOneOwnedBy($owner, $id) : null;
        if (!$rule instanceof SenderRule) {
            throw new NotFoundHttpException('Sender rule not found.');
        }

        return SenderRuleResource::fromEntity($rule);
    }
}
