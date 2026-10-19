<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\OutgoingMessageResource;
use App\Entity\OutgoingMessage;
use App\Repository\OutgoingMessageRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<OutgoingMessageResource>
 */
final readonly class OutgoingMessageProvider implements ProviderInterface
{
    public function __construct(
        private OutgoingMessageRepository $messages,
        private CurrentUser $currentUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): OutgoingMessageResource
    {
        $id = $uriVariables['id'] ?? null;
        $message = \is_string($id) ? $this->messages->findOneOwnedBy($this->currentUser->get(), $id) : null;
        if (!$message instanceof OutgoingMessage) {
            throw new NotFoundHttpException('Outgoing message not found.');
        }

        return OutgoingMessageResource::fromEntity($message);
    }
}
