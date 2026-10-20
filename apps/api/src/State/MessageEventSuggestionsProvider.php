<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MessageEventSuggestions;
use App\Calendar\Assistant\EventExtractor;
use App\Repository\MessageRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<MessageEventSuggestions>
 */
final readonly class MessageEventSuggestionsProvider implements ProviderInterface
{
    public function __construct(
        private MessageRepository $messages,
        private EventExtractor $extractor,
        private CurrentUser $currentUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MessageEventSuggestions
    {
        $user = $this->currentUser->get();
        $id = $uriVariables['id'] ?? null;
        $message = \is_string($id) ? $this->messages->findOneOwnedBy($user, $id) : null;
        if (null === $message) {
            throw new NotFoundHttpException('Message not found.');
        }
        $found = $this->extractor->extract($user, $message);

        return new MessageEventSuggestions($message->getId()->toRfc4122(), $found['events'], $found['deadlines']);
    }
}
