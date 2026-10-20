<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\Unsubscribe\UnsubscribeInput;
use App\ApiResource\UnsubscriptionResource;
use App\Repository\MessageRepository;
use App\Security\CurrentUser;
use App\Unsubscribe\UnsubscribeRefused;
use App\Unsubscribe\Unsubscriptions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @implements ProcessorInterface<UnsubscribeInput, UnsubscriptionResource>
 */
final readonly class UnsubscriptionProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private MessageRepository $messages,
        private Unsubscriptions $unsubscriptions,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): UnsubscriptionResource
    {
        $message = $this->messages->findOneOwnedBy($this->currentUser->get(), $data->messageId)
            ?? throw new NotFoundHttpException('Message not found.');

        return $this->entityManager->wrapInTransaction(function () use ($data, $message): UnsubscriptionResource {
            try {
                return UnsubscriptionResource::fromEntity($this->unsubscriptions->request($message, new \DateTimeImmutable()));
            } catch (UnsubscribeRefused $refused) {
                throw new ValidationException(new ConstraintViolationList([new ConstraintViolation($refused->getMessage(), null, [], $data, 'messageId', $data->messageId)]));
            }
        });
    }
}
