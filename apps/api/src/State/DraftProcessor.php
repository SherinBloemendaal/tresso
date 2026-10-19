<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\ApiResource\DraftResource;
use App\ApiResource\Input\Draft\DraftInput;
use App\ApiResource\Input\Draft\DraftRecipientInput;
use App\Entity\Draft;
use App\Mail\Body\AttachmentUrls;
use App\Entity\MailAccount;
use App\Entity\Message;
use App\Entity\Signature;
use App\Entity\User;
use App\Outbox\DraftFiling;
use App\Repository\DraftRepository;
use App\Repository\MailAccountRepository;
use App\Repository\MessageRepository;
use App\Repository\SignatureRepository;
use App\Security\CurrentUser;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * @implements ProcessorInterface<DraftInput|DraftResource, DraftResource|null>
 */
final readonly class DraftProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private CurrentUser $currentUser,
        private DraftRepository $drafts,
        private MailAccountRepository $accounts,
        private SignatureRepository $signatures,
        private MessageRepository $messages,
        private DraftFiling $filing,
        private AttachmentUrls $urls,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?DraftResource
    {
        $user = $this->currentUser->get();

        if ($operation instanceof DeleteOperationInterface) {
            $this->entityManager->wrapInTransaction(function () use ($user, $uriVariables): void {
                $this->filing->discard($this->lock($user, $uriVariables));
            });

            return null;
        }

        if (!$data instanceof DraftInput) {
            throw new \LogicException('Drafts are written with a DraftInput.');
        }

        // Every reference is resolved before the transaction, so a wrong one is a validation error, not a 500.
        $account = $this->resolve($data->mailAccountId, 'mailAccountId', fn (string $id) => $this->accounts->findOneOwnedBy($user, $id));
        $signature = $this->resolve($data->signatureId, 'signatureId', fn (string $id) => $this->signatures->findOneOwnedBy($user, $id));
        $inReplyTo = $this->resolve($data->inReplyToMessageId, 'inReplyToMessageId', fn (string $id) => $this->messages->findOneOwnedBy($user, $id));
        $now = new \DateTimeImmutable();

        $draft = $this->entityManager->wrapInTransaction(function () use ($data, $user, $uriVariables, $account, $signature, $inReplyTo, $now): Draft {
            if (!isset($uriVariables['id'])) {
                $id = null === $data->draftId ? Uuid::v7() : Uuid::fromString($data->draftId);
                if (null !== $this->entityManager->find(Draft::class, $id)) {
                    throw new ConflictHttpException('A draft with this id exists already.');
                }
                $draft = new Draft($id, $user, $account, $data->kind, $now);
                $this->entityManager->persist($draft);
                $draft->write($account, $this->recipients($data), $data->subject, $data->html, $signature, $data->quoteHtml, $inReplyTo, $now);
                $this->entityManager->flush();

                return $draft;
            }

            $draft = $this->lock($user, $uriVariables);
            if (null === $data->expectedVersion) {
                throw self::invalid('expectedVersion', 'Say which version this update is based on.', null);
            }
            if ($data->expectedVersion !== $draft->getVersion()) {
                throw new ConflictHttpException(\sprintf('The draft changed on another device (version %d, this update is based on %d).', $draft->getVersion(), $data->expectedVersion));
            }
            $previous = $draft->getMailAccount();
            if (null === $previous ? null !== $account : !$previous->getId()->equals($account?->getId())) {
                $this->filing->accountChanged($draft, $previous);
            }
            $draft->write($account, $this->recipients($data), $data->subject, $data->html, $signature, $data->quoteHtml, $inReplyTo, $now);
            $this->entityManager->flush();

            return $draft;
        });

        return DraftResource::fromEntity($draft, $this->urls);
    }

    /**
     * @param array<string, mixed> $uriVariables
     */
    private function lock(User $user, array $uriVariables): Draft
    {
        $id = $uriVariables['id'] ?? null;
        $owned = \is_string($id) ? $this->drafts->findOneOwnedBy($user, $id) : null;
        $draft = null === $owned ? null : $this->entityManager->find(Draft::class, $owned->getId(), LockMode::PESSIMISTIC_WRITE);
        if (!$draft instanceof Draft) {
            throw new NotFoundHttpException('Draft not found.');
        }

        return $draft;
    }

    /**
     * @template T of MailAccount|Signature|Message
     *
     * @param callable(string): ?T $find
     *
     * @return T|null
     */
    private function resolve(?string $id, string $path, callable $find): ?object
    {
        if (null === $id) {
            return null;
        }
        $found = $find($id);
        if (null === $found) {
            throw self::invalid($path, 'Nothing of yours has that id.', $id);
        }

        return $found;
    }

    /**
     * @return array{to: list<array{address: string, name: ?string}>, cc: list<array{address: string, name: ?string}>, bcc: list<array{address: string, name: ?string}>}
     */
    private function recipients(DraftInput $data): array
    {
        return ['to' => self::list($data->to), 'cc' => self::list($data->cc), 'bcc' => self::list($data->bcc)];
    }

    /**
     * @param list<DraftRecipientInput> $recipients
     *
     * @return list<array{address: string, name: ?string}>
     */
    private static function list(array $recipients): array
    {
        $list = [];
        foreach ($recipients as $recipient) {
            $list[] = $recipient->toArray();
        }

        return $list;
    }

    private static function invalid(string $path, string $message, ?string $value): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([new ConstraintViolation($message, null, [], null, $path, $value)]));
    }
}
