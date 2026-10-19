<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\SenderRuleResource;
use App\Classification\ClassificationScheduler;
use App\Repository\SenderRuleRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Forgets a sender rule. Recent mail from that sender is classified again once committed.
 *
 * @implements ProcessorInterface<SenderRuleResource, null>
 */
final readonly class SenderRuleProcessor implements ProcessorInterface
{
    public function __construct(
        private SenderRuleRepository $rules,
        private CurrentUser $currentUser,
        private EntityManagerInterface $entityManager,
        private ClassificationScheduler $classification,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $owner = $this->currentUser->get();
        $id = $uriVariables['id'] ?? null;
        $rule = (\is_string($id) ? $this->rules->findOneOwnedBy($owner, $id) : null)
            ?? throw new NotFoundHttpException('Sender rule not found.');

        $this->entityManager->wrapInTransaction(function () use ($rule, $owner): void {
            $sender = $rule->getSender();
            $this->entityManager->remove($rule);
            $this->entityManager->flush();
            $this->classification->senderChanged($owner->getId(), $sender);
        });

        return null;
    }
}
