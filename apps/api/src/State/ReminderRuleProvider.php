<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ReminderRuleResource;
use App\Entity\ReminderRule;
use App\Repository\ReminderRuleRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<ReminderRuleResource>
 */
final readonly class ReminderRuleProvider implements ProviderInterface
{
    public function __construct(
        private ReminderRuleRepository $rules,
        private CurrentUser $currentUser,
    ) {
    }

    /**
     * @return ReminderRuleResource|list<ReminderRuleResource>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ReminderRuleResource|array
    {
        $user = $this->currentUser->get();
        if ($operation instanceof CollectionOperationInterface) {
            return array_map(ReminderRuleResource::fromEntity(...), $this->rules->findOwnedBy($user));
        }
        $id = $uriVariables['id'] ?? null;
        $rule = \is_string($id) ? $this->rules->findOneOwnedBy($user, $id) : null;
        if (!$rule instanceof ReminderRule) {
            throw new NotFoundHttpException('Reminder rule not found.');
        }

        return ReminderRuleResource::fromEntity($rule);
    }
}
