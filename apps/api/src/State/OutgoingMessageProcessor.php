<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\Input\Outbox\RecipientInput;
use App\ApiResource\Input\Outbox\SendMessageInput;
use App\ApiResource\OutgoingMessageResource;
use App\Entity\MailAccount;
use App\Entity\Message;
use App\Entity\OutgoingMessage;
use App\Enum\OutgoingStatus;
use App\Outbox\Composition;
use App\Outbox\Outbox;
use App\Repository\MailAccountRepository;
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
 * @implements ProcessorInterface<SendMessageInput|OutgoingMessageResource, OutgoingMessageResource>
 */
final readonly class OutgoingMessageProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Outbox $outbox,
        private CurrentUser $currentUser,
        private MailAccountRepository $accounts,
        private MessageRepository $messages,
        private OutgoingMessageRepository $outgoing,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): OutgoingMessageResource
    {
        $user = $this->currentUser->get();

        if ($data instanceof SendMessageInput) {
            $account = $this->accounts->findOneOwnedBy($user, $data->mailAccountId);
            if (!$account instanceof MailAccount) {
                throw self::invalid('mailAccountId', 'No mail account with that id.', $data->mailAccountId);
            }
            $inReplyTo = null;
            if (null !== $data->inReplyToMessageId) {
                $inReplyTo = $this->messages->findOneOwnedBy($user, $data->inReplyToMessageId);
                if (!$inReplyTo instanceof Message) {
                    throw self::invalid('inReplyToMessageId', 'No message with that id.', $data->inReplyToMessageId);
                }
            }
            $now = new \DateTimeImmutable();
            $composition = new Composition(
                $account,
                self::recipients($data->to),
                self::recipients($data->cc),
                self::recipients($data->bcc),
                trim($data->subject),
                $data->html,
                $inReplyTo,
                null === $data->sendAt || $data->sendAt < $now ? $now : $data->sendAt,
            );

            return OutgoingMessageResource::fromEntity($this->entityManager->wrapInTransaction(
                fn (): OutgoingMessage => $this->outbox->queue($composition, $now),
            ));
        }

        $id = $uriVariables['id'] ?? null;
        $owned = \is_string($id) ? $this->outgoing->findOneOwnedBy($user, $id) : null;
        if (!$owned instanceof OutgoingMessage) {
            throw new NotFoundHttpException('Outgoing message not found.');
        }

        return OutgoingMessageResource::fromEntity($this->entityManager->wrapInTransaction(function () use ($owned): OutgoingMessage {
            $message = $this->entityManager->find(OutgoingMessage::class, $owned->getId(), LockMode::PESSIMISTIC_WRITE);
            if (!$message instanceof OutgoingMessage) {
                throw new NotFoundHttpException('Outgoing message not found.');
            }
            if (!\in_array($message->getStatus(), [OutgoingStatus::Scheduled, OutgoingStatus::Queued], true)) {
                throw new ConflictHttpException(\sprintf('This message is %s and can no longer be cancelled.', $message->getStatus()->value));
            }
            $this->outbox->cancel($message);

            return $message;
        }));
    }

    private static function invalid(string $path, string $message, string $value): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([new ConstraintViolation($message, null, [], null, $path, $value)]));
    }

    /**
     * @param list<RecipientInput> $recipients
     *
     * @return list<array{address: string, name: ?string}>
     */
    private static function recipients(array $recipients): array
    {
        return array_map(static fn (RecipientInput $recipient): array => $recipient->toArray(), $recipients);
    }
}
