<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\HandOverResource;
use App\ApiResource\Input\HandOver\CreateHandOver;
use App\ApiResource\Input\HandOver\NudgeHandOver;
use App\ApiResource\Input\HandOver\UpdateHandOver;
use App\Entity\HandOver;
use App\Entity\Message;
use App\Entity\OutgoingMessage;
use App\Entity\User;
use App\Enum\OutgoingStatus;
use App\HandOver\HandOvers;
use App\Repository\HandOverRepository;
use App\Repository\MessageRepository;
use App\Repository\OutgoingMessageRepository;
use App\Security\CurrentUser;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Records, changes, nudges and withdraws the signed-in user's hand-overs.
 *
 * @implements ProcessorInterface<CreateHandOver|UpdateHandOver|NudgeHandOver|HandOverResource, HandOverResource|null>
 */
final readonly class HandOverProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private HandOvers $handOvers,
        private HandOverRepository $repository,
        private MessageRepository $messages,
        private OutgoingMessageRepository $outgoing,
        private CurrentUser $currentUser,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?HandOverResource
    {
        $user = $this->currentUser->get();
        $now = new \DateTimeImmutable();

        // One transaction per write, so change numbers for client sync commit in order.
        if ($data instanceof CreateHandOver) {
            return HandOverResource::fromEntity($this->entityManager->wrapInTransaction(fn (): HandOver => $this->create($user, $data, $now)));
        }

        $owned = $this->owned($user, $uriVariables);

        return $this->entityManager->wrapInTransaction(function () use ($owned, $data, $operation, $now): ?HandOverResource {
            $handOver = $this->entityManager->find(HandOver::class, $owned->getId(), LockMode::PESSIMISTIC_WRITE)
                ?? throw new NotFoundHttpException('Hand-over not found.');

            if ($operation instanceof DeleteOperationInterface) {
                $this->handOvers->withdraw($handOver);

                return null;
            }
            if ($data instanceof NudgeHandOver) {
                if (!$handOver->isOpen()) {
                    throw new ConflictHttpException('This hand-over is closed: there is nobody to nudge.');
                }
                if (HandOvers::nudgeUnderWay($handOver)) {
                    throw new ConflictHttpException('The last nudge is still on its way.');
                }
                $this->handOvers->nudge($handOver, $data->html, null === $data->sendAt || $data->sendAt < $now ? $now : $data->sendAt, $now);

                return HandOverResource::fromEntity($handOver);
            }
            if (!$data instanceof UpdateHandOver) {
                throw new \LogicException(\sprintf('Unexpected input %s for %s.', get_debug_type($data), $operation->getName()));
            }

            if (null !== $data->dueAt) {
                $this->handOvers->reschedule($handOver, $data->dueAt, $now);
            }
            if (true === $data->done) {
                $this->handOvers->markDone($handOver, $now);
            }
            if (false === $data->done) {
                $this->handOvers->reopen($handOver, $now);
            }

            return HandOverResource::fromEntity($handOver);
        });
    }

    private function create(User $user, CreateHandOver $data, \DateTimeImmutable $now): HandOver
    {
        $delegate = $data->delegate ?? throw new \LogicException('Validation guarantees a delegate.');
        $dueAt = $data->dueAt ?? throw new \LogicException('Validation guarantees a due date.');
        $recipient = $delegate->toArray();

        $message = $this->messages->findOneOwnedBy($user, $data->messageId);
        if (!$message instanceof Message) {
            throw self::invalid('messageId', 'No message with that id.', $data->messageId);
        }
        $forward = $this->outgoing->findOneOwnedBy($user, $data->forwardId);
        if (!$forward instanceof OutgoingMessage || $forward->getMailAccount() !== $message->getMailAccount()) {
            throw self::invalid('forwardId', 'No message sent from this account with that id.', $data->forwardId);
        }
        if (\in_array($forward->getStatus(), [OutgoingStatus::Cancelled, OutgoingStatus::Failed], true)) {
            throw self::invalid('forwardId', 'This forward was not sent.', $data->forwardId);
        }
        // Two requests with one forward take turns, so the second sees the first and is refused.
        $this->entityManager->lock($forward, LockMode::PESSIMISTIC_WRITE);
        if ($forward->getInReplyTo() !== $message) {
            throw self::invalid('forwardId', 'This is not a forward of that message.', $data->forwardId);
        }
        if ($this->repository->isForwardUsed($forward)) {
            throw self::invalid('forwardId', 'This forward handed over something already.', $data->forwardId);
        }
        if (!self::isRecipient($forward, $recipient['address'])) {
            throw self::invalid('delegate.address', 'The forward did not go to this address.', $recipient['address']);
        }

        return $this->handOvers->handOver($message, $recipient['address'], $recipient['name'], trim($data->note), $dueAt, $forward, $now);
    }

    private static function isRecipient(OutgoingMessage $forward, string $address): bool
    {
        $recipients = $forward->getRecipients();
        foreach ([...$recipients['to'], ...$recipients['cc'], ...$recipients['bcc']] as $recipient) {
            if (mb_strtolower($recipient['address']) === $address) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function owned(User $user, array $uriVariables): HandOver
    {
        $id = $uriVariables['id'] ?? null;

        return (\is_string($id) ? $this->repository->findOneOwnedBy($user, $id) : null)
            ?? throw new NotFoundHttpException('Hand-over not found.');
    }

    private static function invalid(string $path, string $message, string $value): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([new ConstraintViolation($message, null, [], null, $path, $value)]));
    }
}
